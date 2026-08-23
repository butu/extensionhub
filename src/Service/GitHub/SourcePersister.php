<?php

namespace App\Service\GitHub;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists a validated GitHub candidate onto the source model.
 *
 * Dual-source: if a canonical Extension with the candidate's UUID already exists
 * (typically from the EGO import), the GitHub source is attached to that same
 * Extension.
 *
 * GitHub-only: if no canonical Extension exists yet, a new one is created here
 * with pk left null (Extension::$pk is nullable precisely to allow this, without
 * inventing a fake EGO primary key). Only fields the candidate actually carries
 * are filled in; no EGO-shaped install URL, downloads, or ratings are invented.
 * Such extensions are excluded from the v1 snapshot/comments until v2.
 */
final class SourcePersister
{
    public const SKIP_DUPLICATE_EXTERNAL_IDENTIFIER = 'duplicate_external_identifier';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExtensionSourceRepository $sourceRepository,
        private readonly SourceMetricMeasurementRepository $metricRepository,
        private readonly SourceMapper $mapper,
    ) {
    }

    public function persistCandidate(ExtensionCandidate $candidate, DateTimeInterface $now): SourcePersistResult
    {
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy(['uuid' => $candidate->uuid]);
        $isNewExtension = $extension === null;

        if ($isNewExtension) {
            $extension = new Extension();
            $extension->uuid = $candidate->uuid;
            $extension->pk = null;
        }

        // A brand-new Extension cannot already have a GitHub source attached.
        $source = $isNewExtension ? null : $this->sourceRepository->findOneByExtensionAndType($extension, ExtensionSource::TYPE_GITHUB);

        if ($source === null) {
            $externalIdentifier = $this->mapper->externalIdentifierFor($candidate);
            $collision = $this->sourceRepository->findOneByTypeAndExternalIdentifier(ExtensionSource::TYPE_GITHUB, $externalIdentifier);
            if ($collision !== null && ($isNewExtension || $collision->extension?->id !== $extension->id)) {
                return SourcePersistResult::skip(self::SKIP_DUPLICATE_EXTERNAL_IDENTIFIER);
            }
        }

        // Only a GitHub-only extension takes its canonical display fields from
        // GitHub. As soon as an EGO source exists, EGO owns them, so a refresh
        // run must not overwrite EGO wording with repository wording.
        if ($isNewExtension || !$this->hasEgoSource($extension)) {
            $this->applyCanonicalGithubFacts($extension, $candidate, $now);
        }

        if ($isNewExtension) {
            $this->entityManager->persist($extension);
        }

        $source = $this->mapper->mapToSource($candidate, $source, $now);
        $source->extension = $extension;

        $this->entityManager->persist($source);
        $this->entityManager->flush();

        foreach ($this->mapper->buildMetricMeasurements($source, $candidate, $now) as $measurement) {
            $this->metricRepository->recordMeasurement($source, $measurement->metricType, $measurement->value, $measurement->measuredAt);
        }

        return SourcePersistResult::success($source);
    }

    private function hasEgoSource(Extension $extension): bool
    {
        return $this->sourceRepository->findOneByExtensionAndType($extension, ExtensionSource::TYPE_EGO) !== null;
    }

    /**
     * Write the canonical display fields of a GitHub-only Extension from
     * candidate facts only. No EGO-specific data (install URL, downloads,
     * rating, comment count) is fabricated.
     *
     * Applied to new and already-known GitHub-only extensions alike, so a
     * refresh run also corrects canonical values that an earlier import got
     * wrong — otherwise a GitHub-only extension would keep its very first
     * name and description forever.
     *
     * $now is only a last-resort fallback for the two date fields the
     * Extension entity requires: whenever GitHub reported a real repository
     * creation date or activity date, that fact wins over the import time,
     * so neither date silently becomes "the day we imported it".
     */
    private function applyCanonicalGithubFacts(Extension $extension, ExtensionCandidate $candidate, DateTimeInterface $now): void
    {
        $ownerLogin = $this->firstNonEmpty($candidate->ownerLogin, $this->deriveOwnerLoginFromFullName($candidate->fullName));
        $lastChange = $candidate->lastCommitAt ?? $candidate->lastReleaseAt ?? $now;
        $creationDate = $candidate->repositoryCreatedAt ?? $now;

        $extension->name = $this->firstNonEmpty($candidate->metadataName, $candidate->fullName) ?? $candidate->fullName;
        $extension->description = $this->firstNonEmpty($candidate->metadataDescription, $candidate->description) ?? '';
        $extension->link = $candidate->htmlUrl;
        $extension->sourceUrl = $candidate->htmlUrl;
        $extension->icon ??= '';
        $extension->creator = $ownerLogin ?? '';
        $extension->creator_url = $candidate->ownerHtmlUrl ?? ($ownerLogin !== null ? 'https://github.com/' . $ownerLogin : '');
        $extension->creationDate = DateTime::createFromInterface($creationDate);
        $extension->lastChange = DateTime::createFromInterface($lastChange);
        $extension->supportedShellVersions = $this->mapper->normalizeShellVersions($candidate->shellVersion);
        $extension->downloads = null;
        $extension->rating = null;
        $extension->comments = null;
    }

    private function deriveOwnerLoginFromFullName(string $fullName): ?string
    {
        $owner = explode('/', $fullName, 2)[0] ?? '';

        return $owner === '' ? null : $owner;
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
