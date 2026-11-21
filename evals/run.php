<?php

declare(strict_types=1);

/**
 * The eval: runs every question in evals/cases.json through the agent loop (src/AgentLoop.php),
 * scores each answer (src/EvalScore.php), and prints the scores with their confidence intervals.
 *
 * THIS CALLS THE PAID API. A full run is 28 questions x 2 runs; see README.md, under "The eval", for
 * what it costs. Ask before running it.
 *
 *   php evals/run.php                       every question, 2 runs each, on claude-sonnet-5-5
 *   php evals/run.php --only=deer-boss,crab-boss --reps=1
 *   php evals/run.php --variant=v1          a separate set of results, e.g. after changing a description
 *
 * Options:
 *   --variant=baseline    where results go: .claude/hillclimb/wiki-questions/<variant>/
 *   --model=...           the model to test (default: AgentLoop::MODEL)
 *   --reps=2              runs per question
 *   --only=a,b            run only these case ids
 *   --jobs=4              how many runs at once
 *   --timeout-s=180       time limit for one run; a run that takes longer is recorded as a timeout
 *   --approve-harness     record the runner and grader as approved (see "The harness check" below)
 *
 * Each finished run is written at once, so a crash loses nothing that finished, and running the
 * same command again only runs what's missing. To start a variant again, delete its folder.
 *
 * Output, in .claude/hillclimb/wiki-questions/<variant>/:
 *   results.jsonl          one line per scored run: scores, tokens, tool calls, the route
 *   traces/<id>_rep<k>.json the full conversation of that run
 *   errors.jsonl           runs that failed before giving an answer (API errors, timeouts, a
 *                          different model answering). These are never counted as wrong answers.
 *
 * The harness check: the scores are only comparable while the runner, the agent loop and the grader
 * stay the same. So the first run refuses to start until someone who has read them runs it once with
 * --approve-harness. That records a fingerprint (SHA-256) of those files, and a later change to any
 * of them stops the eval until it is approved again.
 */

require __DIR__ . '/../vendor/autoload.php';

// Where results go. ALDERVAST_EVAL_DIR can point somewhere else, so that a test of the runner itself
// (against a fake API) never touches the real results or the harness approval.
define('FLOW_DIR', getenv('ALDERVAST_EVAL_DIR') ?: __DIR__ . '/../.claude/hillclimb/wiki-questions');
const CASES_FILE = __DIR__ . '/cases.json';
const HARNESS_FILES = ['evals/run.php', 'src/AgentLoop.php', 'src/AgentLoopError.php', 'src/EvalScore.php', 'composer.lock'];

load_env(__DIR__ . '/../.env');

$options = getopt('', ['variant:', 'model:', 'reps:', 'only:', 'jobs:', 'timeout-s:', 'approve-harness', 'worker:', 'rep:']);
$variant = $options['variant'] ?? 'baseline';
$model = $options['model'] ?? AgentLoop::MODEL;
$timeLimit = (float) ($options['timeout-s'] ?? 180);
$dir = FLOW_DIR . '/' . $variant;

if (preg_match('/^(baseline|v\d+)$/', $variant) !== 1) {
    exit("--variant must be \"baseline\" or v1, v2, ... (the report only reads those names).\n");
}

$apiKey = env('ANTHROPIC_API_KEY');
if ($apiKey === '' || str_contains($apiKey, 'your-key-here')) {
    exit("No API key. Copy .env.example to .env and paste your key from https://console.anthropic.com\n");
}

$cases = array_column(json_decode((string) file_get_contents(CASES_FILE), true, flags: JSON_THROW_ON_ERROR)['cases'], null, 'id');

// A worker runs exactly one (case, run) and writes its result. The main process starts the workers.
if (isset($options['worker'])) {
    exit(runOne($cases[$options['worker']], (int) $options['rep'], $variant, $model, $apiKey, $timeLimit, $dir));
}

// --- The main process ------------------------------------------------------------------------

checkHarness(isset($options['approve-harness']));
writeStateFile();

$reps = max(1, (int) ($options['reps'] ?? 2));
$jobs = max(1, (int) ($options['jobs'] ?? 4));
$only = isset($options['only']) ? explode(',', $options['only']) : array_keys($cases);
foreach ($only as $id) {
    if (!isset($cases[$id])) {
        exit("Unknown case id: {$id}\n");
    }
}

// Everything that still has to run: every (case, run) without a result yet.
$done = [];
foreach (readJsonLines("{$dir}/results.jsonl") as $row) {
    $done["{$row['prompt_id']}#{$row['rep']}"] = true;
}
$pending = [];
foreach ($only as $id) {
    for ($rep = 0; $rep < $reps; $rep++) {
        if (!isset($done["{$id}#{$rep}"])) {
            $pending[] = [$id, $rep];
        }
    }
}

printf("Running %d of %d runs on %s (%d at a time). Results: %s\n", count($pending), count($only) * $reps, $model, $jobs, realpathOr($dir));
$started = microtime(true);
$running = [];

while ($pending !== [] || $running !== []) {
    // Start workers until $jobs are running.
    while ($pending !== [] && count($running) < $jobs) {
        [$id, $rep] = array_shift($pending);
        $command = [PHP_BINARY, __FILE__, "--worker={$id}", "--rep={$rep}", "--variant={$variant}", "--model={$model}", "--timeout-s={$timeLimit}"];
        // Xdebug off in the workers: with it on, every run waits for a debugger and is slower.
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['XDEBUG_MODE' => 'off'] + getenv());
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $running[] = ['id' => $id, 'rep' => $rep, 'process' => $process, 'pipes' => $pipes, 'started' => microtime(true), 'out' => ''];
    }

    usleep(200_000);
    foreach ($running as $i => &$worker) {
        $worker['out'] .= stream_get_contents($worker['pipes'][1]);
        stream_get_contents($worker['pipes'][2]); // Xdebug and PHP notices; read so the pipe never fills
        $status = proc_get_status($worker['process']);

        // A worker that outlives its own time limit by a lot is stuck; stop it and record a timeout.
        if ($status['running'] && microtime(true) - $worker['started'] > $timeLimit + 30) {
            proc_terminate($worker['process']);
            appendJsonLine("{$dir}/errors.jsonl", [
                'prompt_id' => $worker['id'], 'rep' => $worker['rep'], 'failure_class' => 'timeout',
                'message' => 'worker stopped after ' . ($timeLimit + 30) . ' s', 'time' => date(DATE_ATOM),
            ]);
            $worker['out'] .= "ERROR {$worker['id']} run {$worker['rep']}: timeout (worker stopped)\n";
            $status['running'] = false;
        }

        if (!$status['running']) {
            echo trim($worker['out']) ?: "ERROR {$worker['id']} run {$worker['rep']}: the worker printed nothing", "\n";
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
            unset($running[$i]);
        }
    }
    unset($worker);
}

printSummary($dir, $only, $model, microtime(true) - $started);

// --- Functions ---------------------------------------------------------------------------------

/** Runs one case once, scores it, and writes the result (or the failure). Returns the exit code. */
function runOne(array $case, int $rep, string $variant, string $model, string $apiKey, float $timeLimit, string $dir): int
{
    $loop = new AgentLoop(AgentLoop::client($apiKey, env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com')), $model);
    $label = "{$case['id']} run {$rep}";

    try {
        $run = $loop->run($case['question'], $timeLimit);
    } catch (AgentLoopError $e) {
        appendJsonLine("{$dir}/errors.jsonl", [
            'prompt_id' => $case['id'], 'rep' => $rep, 'failure_class' => $e->kind,
            'message' => mb_substr($e->getMessage(), 0, 500), 'usage' => $e->usage, 'time' => date(DATE_ATOM),
        ]);
        echo "ERROR {$label}: {$e->kind}: " . mb_strimwidth($e->getMessage(), 0, 120, '…') . "\n";

        return 1;
    } catch (Throwable $e) {
        // A bug in the eval itself, not something the model did.
        appendJsonLine("{$dir}/errors.jsonl", [
            'prompt_id' => $case['id'], 'rep' => $rep, 'failure_class' => 'harness_error',
            'message' => get_class($e) . ': ' . $e->getMessage(), 'time' => date(DATE_ATOM),
        ]);
        echo "ERROR {$label}: harness_error: {$e->getMessage()}\n";

        return 1;
    }

    $scored = EvalScore::grade($case, $run);
    $traceFile = "{$dir}/traces/{$case['id']}_rep{$rep}.json";
    if (!is_dir(dirname($traceFile))) {
        mkdir(dirname($traceFile), 0777, true);
    }
    file_put_contents($traceFile, pretty($run['transcript']));

    // The route: each tool call in order, so that a row shows how Claude got to its answer.
    $route = array_map(
        fn (array $call) => $call['tool'] . ' ' . json_encode((object) $call['input'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $run['calls']
    );

    appendJsonLine("{$dir}/results.jsonl", [
        'prompt_id' => $case['id'],
        'prompt' => $case['question'],
        'tags' => [$case['tags'][0], 'source: ' . $case['source']],
        'rep' => $rep,
        'status' => runStatus($run['stop_reason']),
        'stop_reason' => $run['stop_reason'],
        'grade' => $scored['grade'],
        'explanation' => $scored['explanation'],
        'model' => $run['model'],
        'usage' => $run['usage'],
        'tool_calls' => count($run['calls']),
        'turns' => $run['turns'],
        'latency_s' => round($run['latency_s'], 2),
        'retries' => $run['retries'],
        'meta' => ['variant' => $variant, 'route' => $route, 'answer' => $run['answer'], 'expected' => ['found' => $case['found'], 'answer' => $case['answer']]],
    ]);

    $verdict = $scored['grade']['pass'] === 1 ? 'PASS ' : 'FAIL ';
    echo $verdict . "{$label}: " . (implode(' > ', $route) ?: 'no tools') . ($verdict === 'FAIL ' ? "  ({$scored['explanation']['found']}; {$scored['explanation']['answered']})" : '') . "\n";

    return 0;
}

/**
 * Whether a finished run is scored. Two kinds of run end without a real answer, and both are counted
 * and shown but left out of the scores, rather than scored as wrong answers:
 *   truncated   the answer was cut off at max_tokens
 *   turn_limit  AgentLoop's own limit of MAX_TURNS turns stopped Claude before it answered. Claude
 *               Desktop has no such limit, so this says more about the test setup than about Claude.
 */
function runStatus(string $stopReason): string
{
    return match ($stopReason) {
        'max_tokens' => 'truncated',
        'max_turns' => 'turn_limit',
        default => 'ok',
    };
}

/** Prints the scores for every result of these cases in the variant, not only this run's. */
function printSummary(string $dir, array $only, string $model, float $seconds): void
{
    $rows = array_values(array_filter(readJsonLines("{$dir}/results.jsonl"), fn (array $row) => in_array($row['prompt_id'], $only, true)));
    $scored = array_values(array_filter($rows, fn (array $row) => $row['status'] === 'ok'));
    $allErrors = array_values(array_filter(readJsonLines("{$dir}/errors.jsonl"), fn (array $row) => in_array($row['prompt_id'], $only, true)));
    // A failed run that later succeeded on a second try no longer counts as failed...
    $succeeded = array_flip(array_map(fn (array $row) => "{$row['prompt_id']}#{$row['rep']}", $rows));
    $errors = array_filter($allErrors, fn (array $row) => !isset($succeeded["{$row['prompt_id']}#{$row['rep']}"]));
    $summary = EvalScore::summarise($scored);

    printf("\n%d cases, %d scored runs, finished in %d s.\n", $summary['cases'], $summary['rows'], round($seconds));
    foreach (EvalScore::METRICS as ['id' => $id, 'label' => $label]) {
        $s = $summary['scores'][$id];
        printf("  %-13s %3.0f%%   (95%% interval %.0f%%-%.0f%%)\n", $label, 100 * $s['mean'], 100 * $s['low'], 100 * $s['high']);
    }

    $statuses = array_count_values(array_column($rows, 'status'));
    $byClass = array_count_values(array_column($errors, 'failure_class'));
    printf(
        "  Not scored:   %d cut off at max_tokens; %d stopped at the %d-turn limit; %d failed runs%s\n",
        $statuses['truncated'] ?? 0,
        $statuses['turn_limit'] ?? 0,
        AgentLoop::MAX_TURNS,
        count($errors),
        $byClass ? ' (' . http_build_query($byClass, '', ', ') . ')' : ''
    );

    if ($rows !== []) {
        $usage = array_column($rows, 'usage');
        printf(
            "  Per run:      %.1f tool calls, %.1f s in the API, %s input tokens (%s of them cache reads), %s output tokens\n",
            array_sum(array_column($rows, 'tool_calls')) / count($rows),
            array_sum(array_column($rows, 'latency_s')) / count($rows),
            number_format(array_sum(array_map(fn ($u) => $u['input_tokens'] + $u['cache_read_input_tokens'] + $u['cache_creation_input_tokens'], $usage)) / count($rows)),
            number_format(array_sum(array_column($usage, 'cache_read_input_tokens')) / count($rows)),
            number_format(array_sum(array_column($usage, 'output_tokens')) / count($rows)),
        );
    }

    // ...but every attempt's tokens were billed, including failed ones, so they all count in the cost.
    // A failed attempt is priced at the model that was asked for, since no answer said otherwise.
    $billed = array_merge($rows, array_map(
        fn (array $row) => ['model' => $model, 'usage' => ($row['usage'] ?? []) + ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]],
        $allErrors
    ));
    if ($billed !== []) {
        $cost = EvalScore::cost($billed);
        echo '  Cost:         ' . ($cost === null
            ? 'not measured (a model without a price in EvalScore::PRICES)'
            : sprintf('$%.3f for %d attempts ($%.4f each), failed attempts included', $cost, count($billed), $cost / count($billed))) . "\n";
    }
}

/**
 * The harness check (see the comment at the top): refuses to run when the runner, agent loop or
 * grader changed since someone last approved them with --approve-harness.
 */
function checkHarness(bool $approve): void
{
    $root = dirname(__DIR__);
    $fingerprint = hash('sha256', implode("\n", array_map(fn (string $f) => $f . ' ' . hash_file('sha256', "{$root}/{$f}"), HARNESS_FILES)));
    $file = FLOW_DIR . '/harness.sha256';

    if ($approve) {
        if (!is_dir(FLOW_DIR)) {
            mkdir(FLOW_DIR, 0777, true);
        }
        file_put_contents($file, $fingerprint . "\n");
        echo "Harness approved: " . implode(', ', HARNESS_FILES) . "\n";

        return;
    }
    if (!is_file($file) || trim((string) file_get_contents($file)) !== $fingerprint) {
        fwrite(STDERR, "The runner, agent loop or grader is new or has changed since it was last approved:\n  "
            . implode(', ', HARNESS_FILES) . "\nRead the changes, then run again with --approve-harness.\n");
        exit(2);
    }
}

/** Writes .claude/hillclimb/wiki-questions/_state.json, which tells the report builder what the scores are. */
function writeStateFile(): void
{
    $file = FLOW_DIR . '/_state.json';
    if (is_file($file)) {
        return;
    }
    if (!is_dir(FLOW_DIR)) {
        mkdir(FLOW_DIR, 0777, true);
    }
    $prices = [];
    foreach (EvalScore::PRICES as $id => $p) {
        $prices[$id] = ['in' => $p['input'], 'out' => $p['output']];
    }
    file_put_contents($file, pretty([
        'metrics' => EvalScore::METRICS,
        'perf_fields' => [
            ['id' => 'tool_calls', 'label' => 'Tool calls'],
            ['id' => 'latency_s', 'label' => 'API time', 'unit' => 's'],
            ['id' => 'cost_usd', 'label' => 'Cost', 'unit' => '$'],
        ],
        'prices' => $prices,
    ]));
}

function appendJsonLine(string $file, array $row): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
}

function readJsonLines(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    return array_values(array_filter(array_map(
        fn (string $line) => json_decode($line, true),
        file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    )));
}

function realpathOr(string $path): string
{
    return realpath($path) ?: $path;
}
