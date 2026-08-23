<?php

namespace App\Service\Cron;

/**
 * Single non-blocking lock shared by every cron import endpoint so an
 * overlapping run fails fast (HTTP 409) instead of racing the snapshot build.
 */
final class CronLock
{
    private mixed $handle = null;

    public function __construct(private readonly string $lockFilePath) {}

    public function tryAcquire(): bool
    {
        $lockDir = dirname($this->lockFilePath);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $handle = fopen($this->lockFilePath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
