<?php

declare(strict_types=1);

namespace Aldervast\Tests\Agent;

use Aldervast\Agent\Env;
use PHPUnit\Framework\TestCase;

/** Tests for Env, which reads settings such as the API key from the environment or .env. */
final class EnvTest extends TestCase
{
    private const NAME = 'ALDERVAST_ENV_TEST';

    protected function tearDown(): void
    {
        putenv(self::NAME);
        unset($_ENV[self::NAME]);
    }

    public function test_a_real_environment_variable_wins_over_the_env_file(): void
    {
        $_ENV[self::NAME] = 'from .env';
        putenv(self::NAME . '=from the environment');

        $this->assertSame('from the environment', Env::get(self::NAME));
    }

    public function test_without_one_it_falls_back_to_the_env_file_then_the_default(): void
    {
        $this->assertSame('the default', Env::get(self::NAME, 'the default'));

        $_ENV[self::NAME] = 'from .env';
        $this->assertSame('from .env', Env::get(self::NAME, 'the default'));
    }

    public function test_an_empty_environment_variable_does_not_hide_the_env_file(): void
    {
        $_ENV[self::NAME] = 'from .env';
        putenv(self::NAME . '=');

        $this->assertSame('from .env', Env::get(self::NAME));
    }

    public function test_load_reads_key_value_lines_and_skips_comments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# a comment\n\n" . self::NAME . " = \"quoted value\"\nnot a setting\n");
        Env::load($file);
        unlink($file);

        $this->assertSame('quoted value', $_ENV[self::NAME]);
    }

    public function test_a_missing_file_is_ignored(): void
    {
        Env::load(sys_get_temp_dir() . '/no-such-env-file');

        $this->assertArrayNotHasKey(self::NAME, $_ENV);
    }
}
