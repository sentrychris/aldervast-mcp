<?php

declare(strict_types=1);

namespace Aldervast\Web;

use Closure;
use RuntimeException;

/**
 * Limits how many questions the chatbot answers, because every answer is paid for with the site
 * owner's API key. Two limits apply:
 *
 *   - per visitor: at most $perHour questions from one IP address in any 60 minutes;
 *   - in total: at most $perDay questions a day (UTC) from everyone together, which caps the bill.
 *
 * The counts are kept in one small JSON file. The IP addresses in it are hashed (SHA-256 with a
 * random salt that is kept in the same file), so the file doesn't hold a list of visitors'
 * addresses, but the same visitor still gets the same key. flock() makes the read-change-write
 * safe when several requests arrive at once.
 */
final class RateLimit
{
    private const HOUR_SECONDS = 3600;

    /**
     * @param string       $file    where the counts are kept, e.g. cache/chat-limits.json
     * @param Closure|null $now     returns the current time as a Unix timestamp; the tests pass a fixed clock
     */
    public function __construct(
        private string $file,
        private int $perHour,
        private int $perDay,
        private ?Closure $now = null,
    ) {
    }

    /**
     * Counts one question from this IP address and returns null, or returns why it is refused
     * (and counts nothing). The message is shown to the visitor.
     */
    public function take(string $ip): ?string
    {
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Can't open {$this->file}");
        }
        flock($handle, LOCK_EX);
        try {
            $state = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];
            $refused = $this->apply($state, $ip);
            if ($refused === null) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($state));
            }

            return $refused;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Checks both limits, and counts the question in $state if neither is reached. */
    private function apply(array &$state, string $ip): ?string
    {
        $now = $this->now === null ? time() : ($this->now)();
        $today = gmdate('Y-m-d', $now);
        $state['salt'] ??= bin2hex(random_bytes(16));
        if (($state['day'] ?? '') !== $today) {
            // A new day: the daily count starts again.
            $state['day'] = $today;
            $state['today'] = 0;
        }
        // Keep only the times from the last hour, for every visitor, so the file stays small.
        $visitors = [];
        foreach ($state['visitors'] ?? [] as $key => $times) {
            $recent = array_values(array_filter($times, fn (int $t) => $t > $now - self::HOUR_SECONDS));
            if ($recent !== []) {
                $visitors[$key] = $recent;
            }
        }
        $state['visitors'] = $visitors;

        if ($state['today'] >= $this->perDay) {
            return 'The chatbot has answered as many questions as it can today. Please try again tomorrow.';
        }
        $key = hash('sha256', $state['salt'] . $ip);
        if (count($visitors[$key] ?? []) >= $this->perHour) {
            return "You've asked {$this->perHour} questions in the last hour, which is the limit. Please try again later.";
        }

        $state['visitors'][$key][] = $now;
        $state['today']++;

        return null;
    }
}
