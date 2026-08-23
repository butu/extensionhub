<?php

namespace App\Service;

/**
 * Result of one EgoExtensionImportService::importAll() run.
 */
final class EgoExtensionImportResult
{
    public function __construct(
        public readonly int $extensionsUpdatedCount,
        public readonly int $purgedDownloadMeasurements,
        public readonly int $purgedSourceMetricMeasurements,
    ) {
    }
}
