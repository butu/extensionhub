<?php

namespace App\Service;

use App\Entity\Extension;
use App\Entity\ExtensionComment;
use App\Entity\ExtensionSource;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates/updates the EGO ExtensionSource for a legacy Extension row, reassigns
 * its existing comments, and records current EGO metrics.
 */
final class EgoSourceBackfillService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExtensionSourceRepository $sourceRepository,
        private readonly SourceMetricMeasurementRepository $metricRepository,
        private readonly EgoSourceMapper $mapper,
    ) {
    }

    public function backfillAll(DateTimeInterface $now): EgoSourceBackfillReport
    {
        $report = new EgoSourceBackfillReport();

        /** @var Extension[] $extensions */
        $extensions = $this->entityManager->getRepository(Extension::class)->findAll();

        foreach ($extensions as $extension) {
            $reason = $this->syncExtension($extension, $now);
            if ($reason !== null) {
                $report->recordSkipped($extension->id, $reason);
                continue;
            }

            $report->recordProcessed();
        }

        return $report;
    }

    /**
     * Sync a single extension's EGO source and metrics. Returns null on success,
     * or a skip reason (e.g. "missing_uuid", "duplicate_external_identifier") otherwise.
     */
    public function syncExtension(Extension $extension, DateTimeInterface $now): ?string
    {
        $reason = $this->mapper->validateExtensionForBackfill($extension);
        if ($reason !== null) {
            return $reason;
        }

        $source = $this->sourceRepository->findOneByExtensionAndType($extension, ExtensionSource::TYPE_EGO);

        if ($source === null) {
            $externalIdentifier = $this->mapper->externalIdentifierFor($extension);
            $collision = $this->sourceRepository->findOneByTypeAndExternalIdentifier(ExtensionSource::TYPE_EGO, $externalIdentifier);
            if ($collision !== null && $collision->extension?->id !== $extension->id) {
                return 'duplicate_external_identifier';
            }
        }

        $source = $this->mapper->mapToSource($extension, $source, $now);
        $this->entityManager->persist($source);
        $this->entityManager->flush();

        $this->reassignUnlinkedComments($extension, $source);

        foreach ($this->mapper->buildMetricMeasurements($source, $extension, $now) as $measurement) {
            $this->metricRepository->recordMeasurement($source, $measurement->metricType, $measurement->value, $measurement->measuredAt);
        }

        return null;
    }

    private function reassignUnlinkedComments(Extension $extension, ExtensionSource $source): void
    {
        $comments = $this->entityManager->getRepository(ExtensionComment::class)->findBy([
            'extension' => $extension,
            'source' => null,
        ]);

        if ($comments === []) {
            return;
        }

        $this->mapper->reassignComments($source, $comments);
        $this->entityManager->flush();
    }
}
