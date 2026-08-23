<?php

namespace App\Tests\Service\Cron;

use App\Service\Cron\CronEnv;
use PHPUnit\Framework\TestCase;

class CronEnvTest extends TestCase
{
    public function testReturnsDefaultWhenUnset(): void
    {
        self::assertSame('fallback', CronEnv::read('CRON_ENV_TEST_UNSET_VAR', 'fallback'));
    }

    public function testReadsFromServerSuperglobal(): void
    {
        $_SERVER['CRON_ENV_TEST_VAR'] = 'from-server';

        self::assertSame('from-server', CronEnv::read('CRON_ENV_TEST_VAR', 'fallback'));

        unset($_SERVER['CRON_ENV_TEST_VAR']);
    }

    public function testReadsFromEnvSuperglobalWhenServerIsMissing(): void
    {
        $_ENV['CRON_ENV_TEST_VAR_2'] = 'from-env';

        self::assertSame('from-env', CronEnv::read('CRON_ENV_TEST_VAR_2', 'fallback'));

        unset($_ENV['CRON_ENV_TEST_VAR_2']);
    }
}
