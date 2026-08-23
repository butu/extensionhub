<?php

namespace App\Service\Cron;

/**
 * Runs an ordered list of console commands, stopping at the first failure.
 * The actual command execution is injected as a callable so this stays
 * testable without booting a Symfony kernel.
 */
final class CronCommandChainRunner
{
    /**
     * @param string[] $commands
     * @param callable(string $commandName): array{0: int, 1: string} $executeCommand
     */
    public function run(array $commands, callable $executeCommand): CronCommandChainResult
    {
        $lines = [];
        $overallStartedAt = microtime(true);
        $exitCode = 0;

        foreach ($commands as $commandName) {
            $startedAt = microtime(true);
            [$code, $output] = $executeCommand($commandName);
            $duration = number_format(microtime(true) - $startedAt, 2);

            $lines[] = sprintf('[%s] finished with code %d in %ss', $commandName, $code, $duration);
            $output = trim($output);
            if ($output !== '') {
                $lines[] = $output;
            }

            if ($code !== 0) {
                $exitCode = $code;
                break;
            }
        }

        $overallDuration = number_format(microtime(true) - $overallStartedAt, 2);
        $lines[] = sprintf('total duration: %ss', $overallDuration);

        return new CronCommandChainResult($lines, $exitCode);
    }
}
