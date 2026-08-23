<?php

namespace App\Service\Cron;

/**
 * Outcome of running an ordered cron command chain: the log lines printed to
 * the caller, and the exit code of the command that stopped the chain (0 if
 * every command ran successfully).
 */
final class CronCommandChainResult
{
    /**
     * @param string[] $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $exitCode,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
