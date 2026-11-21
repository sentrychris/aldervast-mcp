<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tests for the small functions in src/helpers.php. */
final class HelpersTest extends TestCase
{
    private const NAME = 'ALDERVAST_HELPERS_TEST';

    protected function tearDown(): void
    {
        putenv(self::NAME);
        unset($_ENV[self::NAME]);
    }

    public function test_env_prefers_a_real_environment_variable_to_the_env_file(): void
    {
        $_ENV[self::NAME] = 'from .env';
        putenv(self::NAME . '=from the environment');

        $this->assertSame('from the environment', env(self::NAME));
    }

    public function test_env_falls_back_to_the_env_file_then_the_default(): void
    {
        $this->assertSame('the default', env(self::NAME, 'the default'));

        $_ENV[self::NAME] = 'from .env';
        $this->assertSame('from .env', env(self::NAME, 'the default'));
    }

    public function test_an_empty_environment_variable_does_not_hide_the_env_file(): void
    {
        $_ENV[self::NAME] = 'from .env';
        putenv(self::NAME . '=');

        $this->assertSame('from .env', env(self::NAME));
    }

    public function test_load_env_reads_key_value_lines_and_skips_comments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# a comment\n\n" . self::NAME . " = \"quoted value\"\nnot a setting\n");
        load_env($file);
        unlink($file);

        $this->assertSame('quoted value', $_ENV[self::NAME]);
    }

    public function test_pretty_prints_indented_json_with_slashes_and_accents_as_they_are(): void
    {
        $this->assertSame("{\n    \"url\": \"a/b\",\n    \"name\": \"Café\"\n}", pretty(['url' => 'a/b', 'name' => 'Café']));
    }
}
