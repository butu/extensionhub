<?php

namespace App\Tests\Service\Cron;

use App\Service\Cron\CronDebugNotifier;
use PHPUnit\Framework\TestCase;

class CronDebugNotifierTest extends TestCase
{
    public function testDoesNothingWhenRecipientIsEmpty(): void
    {
        $called = false;
        $notifier = new CronDebugNotifier(function () use (&$called): bool {
            $called = true;

            return true;
        });

        $notifier->notify('', 'example.com', 'prod', 0, ['line']);

        self::assertFalse($called);
    }

    public function testSendsSubjectReflectingSuccess(): void
    {
        $capturedSubject = null;
        $notifier = new CronDebugNotifier(function (string $to, string $subject) use (&$capturedSubject): bool {
            $capturedSubject = $subject;

            return true;
        });

        $notifier->notify('ops@example.com', 'example.com', 'prod', 0, ['all good']);

        self::assertStringContainsString('OK', $capturedSubject);
    }

    public function testSendsSubjectReflectingFailure(): void
    {
        $capturedSubject = null;
        $notifier = new CronDebugNotifier(function (string $to, string $subject) use (&$capturedSubject): bool {
            $capturedSubject = $subject;

            return true;
        });

        $notifier->notify('ops@example.com', 'example.com', 'prod', 1, ['it broke']);

        self::assertStringContainsString('FAILED', $capturedSubject);
    }

    public function testBodyContainsHostEnvironmentAndLines(): void
    {
        $capturedBody = null;
        $notifier = new CronDebugNotifier(function (string $to, string $subject, string $body) use (&$capturedBody): bool {
            $capturedBody = $body;

            return true;
        });

        $notifier->notify('ops@example.com', 'unknown-host', 'prod', 0, ['line one', 'line two']);

        self::assertStringContainsString('unknown-host', $capturedBody);
        self::assertStringContainsString('prod', $capturedBody);
        self::assertStringContainsString('line one', $capturedBody);
        self::assertStringContainsString('line two', $capturedBody);
    }
}
