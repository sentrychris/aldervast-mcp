<?php

declare(strict_types=1);

namespace Aldervast\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks that the four parts of src/ depend on each other in one direction only:
 *
 *   Tools  <-  Mcp      the MCP server uses the tools
 *   Tools  <-  Agent    the agent loop (agent.php and the eval) uses the tools
 *   Agent  <-  Eval     the eval uses the agent loop
 *
 * src/Tools/ is the product that the MCP server offers, so it must work without the learning demo,
 * the server code or the eval. Without this test, that separation would erode one convenient `use`
 * line at a time. The check reads the source: any mention of another part's namespace counts,
 * comments included, so that a part can't quietly start to rely on another.
 */
final class ArchitectureTest extends TestCase
{
    /** Each part, and the other parts that it may use. */
    private const ALLOWED = [
        'Tools' => [],
        'Mcp' => ['Tools'],
        'Agent' => ['Tools'],
        'Eval' => ['Agent', 'Tools'],
    ];

    public static function parts(): array
    {
        $cases = [];
        foreach (array_keys(self::ALLOWED) as $part) {
            $cases[$part] = [$part];
        }

        return $cases;
    }

    #[DataProvider('parts')]
    public function test_a_part_uses_only_the_parts_that_it_is_allowed_to(string $part): void
    {
        $files = glob(dirname(__DIR__) . "/src/{$part}/*.php");
        $this->assertNotEmpty($files, "src/{$part}/ has no PHP files");

        foreach ($files as $file) {
            // Every mention of a namespace such as Aldervast\Agent\..., in use lines, code or comments.
            preg_match_all('/Aldervast\\\\(\w+)\\\\/', (string) file_get_contents($file), $matches);
            $others = array_diff(array_unique($matches[1]), [$part]);
            $notAllowed = array_values(array_diff($others, self::ALLOWED[$part]));

            $this->assertSame([], $notAllowed, sprintf(
                'src/%s/%s refers to %s, which src/%s/ may not use. Allowed: %s. See ArchitectureTest.',
                $part,
                basename($file),
                implode(', ', $notAllowed),
                $part,
                self::ALLOWED[$part] === [] ? 'nothing else' : implode(', ', self::ALLOWED[$part])
            ));
        }
    }

    public function test_every_folder_in_src_is_one_of_the_parts(): void
    {
        $folders = array_map('basename', glob(dirname(__DIR__) . '/src/*', GLOB_ONLYDIR));
        sort($folders);
        $parts = array_keys(self::ALLOWED);
        sort($parts);

        // A new folder needs a rule here, so that its dependencies are a decision, not an accident.
        $this->assertSame($parts, $folders);
        // And nothing sits loose in src/ outside the parts.
        $this->assertSame([], glob(dirname(__DIR__) . '/src/*.php'));
    }
}
