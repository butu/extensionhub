<?php

namespace App\Service;

use RuntimeException;

/**
 * Single-writer lock helper for atomic snapshot publishing.
 * Prevents concurrent snapshot builds from interfering with each other.
 */
final class ExtensionSnapshotLock
{
    private mixed $lockHandle = null;

    public function __construct(
        private string $lockFilePath,
    ) {}

    /**
     * Acquire an exclusive lock, blocking until available.
     * 
     * @throws RuntimeException if the lock cannot be acquired after timeout
     */
    public function acquire(int $timeoutSeconds = 10): void
    {
        // Create lock file directory if it doesn't exist
        $lockDir = dirname($this->lockFilePath);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $this->lockHandle = fopen($this->lockFilePath, 'c');
        if ($this->lockHandle === false) {
            throw new RuntimeException("Cannot open lock file: {$this->lockFilePath}");
        }

        // Non-blocking lock attempt
        $endTime = time() + $timeoutSeconds;
        while (time() < $endTime) {
            if (flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                return; // Lock acquired
            }
            usleep(100000); // Sleep for 100ms before retry
        }

        fclose($this->lockHandle);
        $this->lockHandle = null;

        throw new RuntimeException("Cannot acquire snapshot lock (timeout after {$timeoutSeconds}s)");
    }

    /**
     * Release the lock.
     */
    public function release(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Ensure lock is released on destruction.
     */
    public function __destruct()
    {
        $this->release();
    }
}
