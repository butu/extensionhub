<?php

namespace App\Tests\Service\Cron;

use App\Service\Cron\CronCommandChainRunner;
use PHPUnit\Framework\TestCase;

class CronCommandChainRunnerTest extends TestCase
{
    public function testRunsCommandsInOrderAndSucceeds(): void
    {
        $executed = [];
        $executor = function (string $commandName) use (&$executed): array {
            $executed[] = $commandName;

            return [0, "ran {$commandName}"];
        };

        $result = (new CronCommandChainRunner())->run(
            ['app:update-extensions', 'app:parse-comments', 'app:build-extension-snapshot'],
            $executor,
        );

        self::assertSame(
            ['app:update-extensions', 'app:parse-comments', 'app:build-extension-snapshot'],
            $executed,
            'commands must run in the exact configured order'
        );
        self::assertTrue($result->isSuccessful());
        self::assertSame(0, $result->exitCode);
    }

    public function testStopsAtFirstFailingCommand(): void
    {
        $executed = [];
        $executor = function (string $commandName) use (&$executed): array {
            $executed[] = $commandName;

            return $commandName === 'app:parse-comments' ? [1, 'boom'] : [0, ''];
        };

        $result = (new CronCommandChainRunner())->run(
            ['app:update-extensions', 'app:parse-comments', 'app:build-extension-snapshot'],
            $executor,
        );

        self::assertSame(['app:update-extensions', 'app:parse-comments'], $executed);
        self::assertFalse($result->isSuccessful());
        self::assertSame(1, $result->exitCode);
    }

    public function testOutputAndDurationLinesArePresent(): void
    {
        $executor = static fn (string $commandName): array => [0, 'some command output'];

        $result = (new CronCommandChainRunner())->run(['app:update-github-extensions'], $executor);

        self::assertStringContainsString('some command output', implode("\n", $result->lines));
        self::assertStringContainsString('total duration:', implode("\n", $result->lines));
    }
}
