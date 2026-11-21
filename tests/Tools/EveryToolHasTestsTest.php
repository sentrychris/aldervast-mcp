<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tools\Tools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Checks that every tool in src/Tools/Tools.php has its own test class, with real tests in it.
 *
 * AGENTS.md asks for a test class for every new tool. This test turns that rule into a check: if a
 * tool is added to Tools::ALL without tests, composer test fails and says what to create.
 *
 * For each tool, for example SearchWikiTool, it checks that:
 *   1. the file tests/Tools/SearchWikiToolTest.php exists,
 *   2. it defines the class Aldervast\Tests\Tools\SearchWikiToolTest, which PHPUnit can run,
 *   3. that class has at least MIN_TESTS test methods of its own, and
 *   4. the file calls SearchWikiTool::run(), so the tests use the tool itself.
 *
 * What it can't check is whether the tests are good. phpunit.xml sets failOnRisky, so a test method
 * with no assertions already fails the run. Beyond that, a person still needs to read new tests.
 */
final class EveryToolHasTestsTest extends TestCase
{
    private const MIN_TESTS = 3;

    /** One case per tool, named after the tool's class (e.g. "SearchWikiTool"), so a failure names the tool. */
    public static function tools(): array
    {
        $cases = [];
        foreach (Tools::ALL as $class) {
            $tool = (new ReflectionClass($class))->getShortName();
            $cases[$tool] = [$tool];
        }

        return $cases;
    }

    #[DataProvider('tools')]
    public function test_tool_has_a_test_class_with_real_tests(string $tool): void
    {
        // This test sits in tests/Tools/, next to the tool tests, in the same namespace.
        $file = __DIR__ . "/{$tool}Test.php";
        $testClass = __NAMESPACE__ . "\\{$tool}Test";
        $howTo = 'See "How to add a tool" in AGENTS.md.';

        // 1. The file exists.
        $this->assertFileExists($file, "{$tool} has no tests. Create tests/Tools/{$tool}Test.php. {$howTo}");

        // 2. It defines the right class (Composer's autoloader loads it from the file), and PHPUnit can run it.
        $this->assertTrue(class_exists($testClass), "tests/Tools/{$tool}Test.php must define the class {$testClass}.");
        $this->assertTrue(is_subclass_of($testClass, TestCase::class), "{$testClass} must extend FixtureTestCase (or PHPUnit's TestCase).");

        // 3. It has enough test methods of its own (inherited ones don't count).
        $tests = array_filter(
            (new ReflectionClass($testClass))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => $m->class === $testClass
                && (str_starts_with($m->name, 'test') || $m->getAttributes(Test::class) !== [])
        );
        $this->assertGreaterThanOrEqual(
            self::MIN_TESTS,
            count($tests),
            "{$testClass} has " . count($tests) . ' test methods; it needs at least ' . self::MIN_TESTS . ". {$howTo}"
        );

        // 4. The tests call the tool itself, not only other classes.
        $this->assertStringContainsString(
            "{$tool}::run(",
            (string) file_get_contents($file),
            "tests/Tools/{$tool}Test.php never calls {$tool}::run(), so it doesn't test what the tool returns."
        );
    }
}
