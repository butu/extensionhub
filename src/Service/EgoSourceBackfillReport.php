<?php

namespace App\Service;

/**
 * Result of a one-time or incremental EGO -> ExtensionSource backfill run.
 */
final class EgoSourceBackfillReport
{
    private int $processedCount = 0;

    /** @var array<int, array{extensionId: ?int, reason: string}> */
    private array $skipped = [];

    public function recordProcessed(): void
    {
        $this->processedCount++;
    }

    public function recordSkipped(?int $extensionId, string $reason): void
    {
        $this->skipped[] = ['extensionId' => $extensionId, 'reason' => $reason];
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    /**
     * @return array<int, array{extensionId: ?int, reason: string}>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function getSkippedCount(): int
    {
        return count($this->skipped);
    }
}
