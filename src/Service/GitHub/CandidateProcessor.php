<?php

namespace App\Service\GitHub;

use App\Entity\ExtensionSource;

/**
 * Turns already-loaded repository facts into either a fully-built
 * {@see ExtensionCandidate} or a skip reason, covering exactly the
 * workflow step that is duplicated today between DiscoveryRunner and
 * SourceRefreshRunner: eligibility check, metadata.json lookup, release
 * selection, and screenshot/icon resolution.
 *
 * Persisting the resulting candidate stays the caller's responsibility
 * ({@see SourcePersister} is already shared); this class never persists
 * anything itself, so "nothing persisted after a rate-limit abort" remains
 * a property of the caller's loop, not of this class. See
 * {@see self::process()} for the rate-limit-vs-skip rule.
 */
final class CandidateProcessor
{
    public const SKIP_INVALID_FULL_NAME = 'invalid_full_name';
    public const SKIP_CANDIDATE_LOAD_FAILED = 'candidate_load_failed';

    public function __construct(
        private readonly RepositoryEligibilityChecker $eligibilityChecker,
        private readonly CandidateLoader $candidateLoader,
        private readonly ReleaseSelector $releaseSelector,
        private readonly ScreenshotResolver $screenshotResolver,
        private readonly IconResolver $iconResolver,
    ) {
    }

    /**
     * $requireMinimumStars is forwarded as-is to
     * {@see RepositoryEligibilityChecker::evaluate()}; only
     * {@see TargetedRepositoryLoader} passes false.
     *
     * A known source whose freshly reported pushed_at still matches its
     * stored lastCommitAt is rebuilt from the stored deep facts (metadata,
     * release, screenshot, icon) without any of those API calls — the fresh
     * repository facts alone keep stars/forks and canonical fields current.
     *
     * @throws ApiException only when the failure is rate-limited; any other
     *                       API failure while loading metadata, releases,
     *                       the screenshot, or the icon is turned into a
     *                       {@see self::SKIP_CANDIDATE_LOAD_FAILED} skip.
     */
    public function process(
        string $token,
        RepositoryDetails $repository,
        ?ExtensionSource $existing = null,
        bool $requireMinimumStars = true,
    ): CandidateProcessResult {
        $eligibility = $this->eligibilityChecker->evaluate($repository->summary(), $requireMinimumStars);
        if (!$eligibility->eligible) {
            return CandidateProcessResult::skip($eligibility->skipReason);
        }

        if ($existing !== null && $this->unchangedSinceLastRefresh($existing, $repository)) {
            $candidate = $this->candidateFromKnownSource($existing, $repository);
            if ($candidate !== null) {
                return CandidateProcessResult::success($candidate);
            }
        }

        $ownerAndRepo = $this->splitFullName($repository->fullName);
        if ($ownerAndRepo === null) {
            return CandidateProcessResult::skip(self::SKIP_INVALID_FULL_NAME);
        }
        [$owner, $repo] = $ownerAndRepo;

        try {
            $metadataResult = $this->candidateLoader->loadMetadata($token, $owner, $repo);
            if (!$metadataResult->valid) {
                return CandidateProcessResult::skip($metadataResult->skipReason ?? 'metadata_invalid');
            }

            $releases = $this->candidateLoader->loadReleases($token, $owner, $repo);
            $screenshotUrl = $this->screenshotResolver->resolve($token, $owner, $repo, $existing?->displayScreenshot);
            $iconUrl = $this->iconResolver->resolve($token, $owner, $repo, $existing?->displayIcon);
        } catch (ApiException $exception) {
            if ($exception->isRateLimited()) {
                throw $exception;
            }

            return CandidateProcessResult::skip(self::SKIP_CANDIDATE_LOAD_FAILED);
        }

        $asset = $this->releaseSelector->selectInstallableRelease($releases);
        $release = $this->releaseOf($releases, $asset);

        return CandidateProcessResult::success(new ExtensionCandidate(
            repositoryId: $repository->id,
            fullName: $repository->fullName,
            htmlUrl: $repository->htmlUrl,
            stargazersCount: $repository->stargazersCount,
            forksCount: $repository->forksCount,
            uuid: (string) $metadataResult->uuid,
            shellVersion: $metadataResult->shellVersion,
            description: $repository->description,
            ownerLogin: $repository->ownerLogin,
            ownerHtmlUrl: $repository->ownerHtmlUrl,
            metadataName: $metadataResult->name,
            metadataDescription: $metadataResult->description,
            repositoryCreatedAt: $repository->createdAt,
            lastCommitAt: $repository->pushedAt,
            installUrl: $asset?->downloadUrl,
            lastReleaseAt: $release?->publishedAt,
            screenshotUrl: $screenshotUrl,
            iconUrl: $iconUrl,
        ));
    }

    /**
     * The refresh flow always re-loads repository facts first (stars and
     * forks must be measured on every run), so a stored source whose
     * lastCommitAt equals the freshly reported pushed_at cannot have gained
     * new commits, tags, README images, or metadata.json content since.
     * Timestamps are compared as instants, immune to timezone display.
     */
    private function unchangedSinceLastRefresh(ExtensionSource $source, RepositoryDetails $repository): bool
    {
        return $source->lastCommitAt !== null
            && $repository->pushedAt !== null
            && $source->lastCommitAt->getTimestamp() === $repository->pushedAt->getTimestamp();
    }

    /**
     * Rebuilds a candidate from the stored deep facts plus the fresh
     * repository facts, or null when the stored data is insufficient (no
     * canonical extension/uuid yet), making the caller fall back to the
     * full loading path.
     */
    private function candidateFromKnownSource(ExtensionSource $source, RepositoryDetails $repository): ?ExtensionCandidate
    {
        $uuid = $source->extension?->uuid;
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return new ExtensionCandidate(
            repositoryId: $repository->id,
            fullName: $repository->fullName,
            htmlUrl: $repository->htmlUrl,
            stargazersCount: $repository->stargazersCount,
            forksCount: $repository->forksCount,
            uuid: $uuid,
            // Stored shell versions are already normalized; re-normalizing
            // keeps them stable through the mapper.
            shellVersion: $source->supportedShellVersions ?? [],
            description: $repository->description,
            ownerLogin: $repository->ownerLogin,
            ownerHtmlUrl: $repository->ownerHtmlUrl,
            metadataName: $source->displayName,
            metadataDescription: $source->displayDescription,
            repositoryCreatedAt: $repository->createdAt,
            lastCommitAt: $repository->pushedAt,
            installUrl: $source->installUrl,
            lastReleaseAt: $source->lastReleaseAt,
            screenshotUrl: $source->displayScreenshot,
            iconUrl: $source->displayIcon,
        );
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function splitFullName(string $fullName): ?array
    {
        $parts = explode('/', $fullName, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$owner, $repo] = $parts;
        if (!$this->isValidPathSegment($owner) || !$this->isValidPathSegment($repo)) {
            return null;
        }

        return [$owner, $repo];
    }

    private function isValidPathSegment(string $segment): bool
    {
        return $segment !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $segment) === 1;
    }

    /**
     * @param Release[] $releases
     */
    private function releaseOf(array $releases, ?ReleaseAsset $asset): ?Release
    {
        if ($asset === null) {
            return null;
        }

        foreach ($releases as $release) {
            if (in_array($asset, $release->assets, true)) {
                return $release;
            }
        }

        return null;
    }
}
