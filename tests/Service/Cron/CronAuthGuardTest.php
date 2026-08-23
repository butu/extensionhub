<?php

namespace App\Tests\Service\Cron;

use App\Service\Cron\CronAuthGuard;
use PHPUnit\Framework\TestCase;

class CronAuthGuardTest extends TestCase
{
    public function testBasicAuthIsSkippedWhenNeitherCredentialIsConfigured(): void
    {
        $guard = new CronAuthGuard();

        self::assertTrue($guard->isBasicAuthValid('', '', '', ''));
    }

    public function testBasicAuthRejectsWrongCredentialsWhenConfigured(): void
    {
        $guard = new CronAuthGuard();

        self::assertFalse($guard->isBasicAuthValid('user', 'pass', 'user', 'wrong'));
        self::assertFalse($guard->isBasicAuthValid('user', 'pass', 'wrong', 'pass'));
        self::assertFalse($guard->isBasicAuthValid('user', 'pass', '', ''));
    }

    public function testBasicAuthAcceptsMatchingCredentials(): void
    {
        $guard = new CronAuthGuard();

        self::assertTrue($guard->isBasicAuthValid('user', 'pass', 'user', 'pass'));
    }

    public function testTokenIsRejectedWhenExpectedTokenIsEmpty(): void
    {
        $guard = new CronAuthGuard();

        self::assertFalse($guard->isTokenValid('', ''));
        self::assertFalse($guard->isTokenValid('', 'anything'));
    }

    public function testTokenMustMatchExactly(): void
    {
        $guard = new CronAuthGuard();

        self::assertTrue($guard->isTokenValid('secret-token', 'secret-token'));
        self::assertFalse($guard->isTokenValid('secret-token', 'other-token'));
    }

    public function testEgoAndGithubTokensAreNotInterchangeable(): void
    {
        $guard = new CronAuthGuard();

        self::assertFalse($guard->isTokenValid('ego-token', 'github-token'));
    }
}
