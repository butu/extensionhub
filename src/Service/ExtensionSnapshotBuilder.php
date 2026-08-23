<?php

namespace App\Service;

use App\Dto\CommentSnapshotItem;
use App\Dto\ExtensionSnapshotItem;
use App\Dto\ScoreComponents;
use App\Dto\SourceSnapshotItem;
use App\Entity\Extension;
use App\Entity\ExtensionComment;
use App\Entity\ExtensionSource;
use App\Repository\ExtensionCommentRepository;
use App\Repository\ExtensionRepository;
use App\Repository\ExtensionSourceRepository;
use App\Dto\SourceTrendSignal;
use App\Entity\SourceMetricMeasurement;
use App\Repository\SourceMetricMeasurementRepository;
use DateTime;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds and publishes the public extensions snapshot v2.
 *
 * Orchestrates:
 * - Loading all extensions and their sources (EGO and/or GitHub) from the database
 * - Mapping each extension onto the source-neutral v2 item contract (App\Service\ExtensionSnapshotMapper)
 * - Computing the source-normalized score and its components across the whole batch (App\Service\ExtensionScoreCalculator)
 * - Validating the final payload against the v2 contract
 * - Atomically publishing to public/data/extensions.json and public/data/extensions.v2.json
 * - Building and publishing the comments snapshot to public/data/comments.json, grouped by extension uuid
 */
final class ExtensionSnapshotBuilder
{
    /** @see SnapshotSchemaValidator::SCHEMA_VERSION single source of truth */
    private const SCHEMA_VERSION = SnapshotSchemaValidator::SCHEMA_VERSION;
    /** @see SnapshotSchemaValidator::PAGE_SIZE single source of truth */
    private const PAGE_SIZE = SnapshotSchemaValidator::PAGE_SIZE;

    public function __construct(
        private ExtensionRepository $extensionRepository,
        private ExtensionSourceRepository $sourceRepository,
        private SourceMetricMeasurementRepository $metricRepository,
        private ExtensionCommentRepository $commentRepository,
        private ExtensionSnapshotMapper $mapper,
        private ExtensionScoreCalculator $scoreCalculator,
        private ExtensionTrendCalculator $trendCalculator,
        private string $projectDir,
        private SnapshotSchemaValidator $schemaValidator = new SnapshotSchemaValidator(),
        private ?SnapshotPublisher $snapshotPublisher = null,
    ) {
        $this->snapshotPublisher ??= new SnapshotPublisher($this->projectDir);
    }

    /**
     * Build the snapshot and return it as a JSON string.
     *
     * @throws RuntimeException if the snapshot cannot be built or validated
     */
    public function buildToString(): string
    {
        $extensions = $this->extensionRepository->findAllForSnapshot();

        $extensionIds = array_values(array_filter(
            array_map(static fn (Extension $extension): ?int => $extension->id, $extensions),
            static fn (?int $id): bool => $id !== null
        ));

        $sourcesByExtensionId = $this->sourceRepository->findAllGroupedByExtensionIds($extensionIds);

        $allSourceIds = [];
        foreach ($sourcesByExtensionId as $sources) {
            foreach ($sources as $source) {
                if ($source->id !== null) {
                    $allSourceIds[] = $source->id;
                }
            }
        }
        $trendDataBySourceId = $this->metricRepository->findTrendDeltasForSources($allSourceIds, new DateTime('UTC'));
        $latestMetricsBySourceId = $this->extractLatestValues($trendDataBySourceId);

        $items = [];
        foreach ($extensions as $extension) {
            if ($extension->id === null) {
                continue;
            }

            $sources = $sourcesByExtensionId[$extension->id] ?? [];
            $metricsBySourceType = $this->metricsBySourceType($sources, $latestMetricsBySourceId);
            $trendDeltasBySourceType = $this->trendDeltasBySourceType($sources, $trendDataBySourceId);

            $item = $this->mapper->mapExtension($extension, $sources, $metricsBySourceType, $trendDeltasBySourceType);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $this->applyScores($items, $sourcesByExtensionId, $extensions, $latestMetricsBySourceId);
        $this->applyTrendScores($items, $sourcesByExtensionId, $extensions, $trendDataBySourceId);

        // Validate uniqueness of uuid and path
        $this->validateUniqueness($items);

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => (new DateTime('UTC'))->format('c'),
            'count' => count($items),
            'pageSize' => self::PAGE_SIZE,
            'items' => array_map($this->serializeItem(...), $items),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->schemaValidator->validate($payload);

        return $json;
    }

    /**
     * Publish the snapshot to the public directory, atomically.
     *
     * Publishes both the stable `extensions.json` name and the explicit
     * `extensions.v2.json` version alias, byte-identical, as this clean
     * break replaces the retired `extensions.v1.json` alias entirely.
     *
     * @throws RuntimeException if publish fails
     */
    public function publish(string $json): void
    {
        $this->snapshotPublisher->publish(
            'public/data/extensions.json',
            $json,
            'public/data/extensions.v2.json',
            function (string $written): void {
                $payload = json_decode($written, true, 512, JSON_THROW_ON_ERROR);
                $this->schemaValidator->validate($payload);
            },
        );
    }

    /**
     * Build the comments snapshot and return it as a JSON string.
     *
     * Groups all qualifying comments (rating > 0) by canonical extension
     * uuid instead of the retired EGO pk.
     *
     * @throws RuntimeException if the snapshot cannot be built
     */
    public function buildCommentsToString(): string
    {
        $groupedComments = $this->commentRepository->findAllGroupedByExtensionUuid();

        $commentsPayload = [];
        foreach ($groupedComments as $uuid => $comments) {
            $commentsPayload[$uuid] = array_map(
                fn (ExtensionComment $comment): array => $this->serializeComment(
                    $this->mapComment($comment)
                ),
                $comments
            );
        }

        $payload = [
            'schemaVersion' => 1,
            'generatedAt' => (new DateTime('UTC'))->format('c'),
            'comments' => $commentsPayload,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Publish the comments snapshot to the public directory, atomically.
     *
     * @throws RuntimeException if publish fails
     */
    public function publishComments(string $json): void
    {
        $this->snapshotPublisher->publish(
            'public/data/comments.json',
            $json,
            null,
            function (string $written, string $temporaryPath): void {
                $payload = json_decode($written, true, 512, JSON_THROW_ON_ERROR);
                if (!isset($payload['schemaVersion']) || !isset($payload['comments'])) {
                    @unlink($temporaryPath);
                    throw new RuntimeException("Invalid comments snapshot structure");
                }
            },
        );
    }

    /**
     * Build the sourceType => [metricType => value] map for one extension's
     * sources, from the batch-loaded latest-metrics-by-source-id lookup.
     *
     * @param ExtensionSource[] $sources
     * @param array<int, array<string, float>> $latestMetricsBySourceId
     * @return array<string, array<string, float>>
     */
    private function metricsBySourceType(array $sources, array $latestMetricsBySourceId): array
    {
        $result = [];
        foreach ($sources as $source) {
            if ($source->sourceType === null || $source->id === null) {
                continue;
            }

            $result[$source->sourceType] = $latestMetricsBySourceId[$source->id] ?? [];
        }

        return $result;
    }

    /**
     * Flatten findTrendDeltasForSources()'s per-source 'latest' value back
     * into the plain sourceId => metricType => value shape the existing
     * popularity scoring and "current metrics" mapping already expect,
     * without querying the latest values a second time.
     *
     * @param array<int, array<string, array{latest: float, delta1d: ?float, delta7d: ?float, delta30d: ?float}>> $trendDataBySourceId
     * @return array<int, array<string, float>>
     */
    private function extractLatestValues(array $trendDataBySourceId): array
    {
        $latest = [];
        foreach ($trendDataBySourceId as $sourceId => $metrics) {
            foreach ($metrics as $metricType => $data) {
                $latest[$sourceId][$metricType] = $data['latest'];
            }
        }

        return $latest;
    }

    /**
     * Build the sourceType => [metricType => deltas] map for one
     * extension's sources, from the batch-loaded trend data.
     *
     * @param ExtensionSource[] $sources
     * @param array<int, array<string, array{latest: float, delta1d: ?float, delta7d: ?float, delta30d: ?float}>> $trendDataBySourceId
     * @return array<string, array<string, array{delta1d: ?float, delta7d: ?float, delta30d: ?float}>>
     */
    private function trendDeltasBySourceType(array $sources, array $trendDataBySourceId): array
    {
        $result = [];
        foreach ($sources as $source) {
            if ($source->sourceType === null || $source->id === null) {
                continue;
            }

            $deltas = [];
            foreach ($trendDataBySourceId[$source->id] ?? [] as $metricType => $data) {
                $deltas[$metricType] = [
                    'delta1d' => $data['delta1d'],
                    'delta7d' => $data['delta7d'],
                    'delta30d' => $data['delta30d'],
                ];
            }

            $result[$source->sourceType] = $deltas;
        }

        return $result;
    }

    /**
     * Compute and attach score/scoreComponents to every mapped item, using
     * the raw popularity/freshness signals of every extension in the batch
     * so percentile ranks are normalized against the full population.
     *
     * @param ExtensionSnapshotItem[] $items
     * @param array<int, ExtensionSource[]> $sourcesByExtensionId
     * @param Extension[] $extensions
     * @param array<int, array<string, float>> $latestMetricsBySourceId
     */
    private function applyScores(
        array $items,
        array $sourcesByExtensionId,
        array $extensions,
        array $latestMetricsBySourceId,
    ): void {
        $extensionsByUuid = [];
        foreach ($extensions as $extension) {
            if ($extension->uuid !== null && $extension->id !== null) {
                $extensionsByUuid[$extension->uuid] = $extension;
            }
        }

        $signalsByUuid = [];
        foreach ($items as $item) {
            $extension = $extensionsByUuid[$item->uuid] ?? null;
            if ($extension === null || $extension->id === null) {
                continue;
            }

            $sources = $sourcesByExtensionId[$extension->id] ?? [];
            $signals = [];
            foreach ($sources as $source) {
                $metrics = ($source->sourceType !== null && $source->id !== null)
                    ? ($latestMetricsBySourceId[$source->id] ?? [])
                    : [];
                $signals[] = $this->mapper->buildRawSignal($source, $metrics);
            }

            $signalsByUuid[$item->uuid] = $signals;
        }

        $scores = $this->scoreCalculator->calculateBatch($signalsByUuid);

        foreach ($items as $item) {
            $result = $scores[$item->uuid] ?? null;
            if ($result === null) {
                continue;
            }

            $item->score = $result['score'];
            $item->scoreComponents = $result['components'];
        }
    }

    /**
     * Compute and attach the top-level `trendScore` to every mapped item,
     * from each extension's sources' 7-day deltas (falling back to 1-day
     * deltas, see ExtensionTrendCalculator) relative to their current
     * metric value. Mirrors applyScores()'s batch-population shape so
     * trend normalization is likewise computed once, against the full
     * batch, per source type.
     *
     * @param ExtensionSnapshotItem[] $items
     * @param array<int, ExtensionSource[]> $sourcesByExtensionId
     * @param Extension[] $extensions
     * @param array<int, array<string, array{latest: float, delta1d: ?float, delta7d: ?float, delta30d: ?float}>> $trendDataBySourceId
     */
    private function applyTrendScores(
        array $items,
        array $sourcesByExtensionId,
        array $extensions,
        array $trendDataBySourceId,
    ): void {
        $extensionsByUuid = [];
        foreach ($extensions as $extension) {
            if ($extension->uuid !== null && $extension->id !== null) {
                $extensionsByUuid[$extension->uuid] = $extension;
            }
        }

        $signalsByUuid = [];
        foreach ($items as $item) {
            $extension = $extensionsByUuid[$item->uuid] ?? null;
            if ($extension === null || $extension->id === null) {
                continue;
            }

            $sources = $sourcesByExtensionId[$extension->id] ?? [];
            $signals = [];
            foreach ($sources as $source) {
                $metricType = $this->trendMetricTypeFor($source->sourceType ?? '');
                if ($metricType === null || $source->id === null) {
                    continue;
                }

                $trendData = $trendDataBySourceId[$source->id][$metricType] ?? [];
                $signals[] = new SourceTrendSignal(
                    sourceType: $source->sourceType ?? '',
                    delta7d: $trendData['delta7d'] ?? null,
                    delta1d: $trendData['delta1d'] ?? null,
                    currentValue: $trendData['latest'] ?? 0.0,
                );
            }

            $signalsByUuid[$item->uuid] = $signals;
        }

        $trendScores = $this->trendCalculator->calculateBatch($signalsByUuid);

        foreach ($items as $item) {
            $item->trendScore = $trendScores[$item->uuid] ?? 0;
        }
    }

    /**
     * The metric that feeds trend eligibility/ranking for a source type:
     * EGO downloads, GitHub stars. Every other source type has none.
     */
    private function trendMetricTypeFor(string $sourceType): ?string
    {
        return match ($sourceType) {
            ExtensionSource::TYPE_EGO => SourceMetricMeasurement::METRIC_DOWNLOADS,
            ExtensionSource::TYPE_GITHUB => SourceMetricMeasurement::METRIC_STARS,
            default => null,
        };
    }

    /**
     * Map an ExtensionComment entity to a CommentSnapshotItem DTO.
     */
    private function mapComment(ExtensionComment $comment): CommentSnapshotItem
    {
        return new CommentSnapshotItem(
            authorUsername: $comment->authorUsername ?? '',
            authorUrl: $comment->authorUrl,
            gravatar: $comment->gravatar,
            comment: $comment->comment ?? '',
            rating: $comment->rating ?? 0,
            isExtensionCreator: $comment->isExtensionCreator,
            commentDate: $comment->commentDate?->format('c') ?? '',
        );
    }

    /**
     * Serialize a comment snapshot item to an array for JSON encoding.
     */
    private function serializeComment(CommentSnapshotItem $item): array
    {
        return [
            'author' => $item->authorUsername,
            'authorUrl' => $item->authorUrl,
            'gravatar' => $item->gravatar,
            'comment' => $item->comment,
            'rating' => $item->rating,
            'isCreator' => $item->isExtensionCreator,
            'date' => $item->commentDate,
        ];
    }

    /**
     * Validate uniqueness constraints on the snapshot items.
     *
     * @param ExtensionSnapshotItem[] $items
     * @throws InvalidArgumentException if duplicates found
     */
    private function validateUniqueness(array $items): void
    {
        $uuids = [];
        $paths = [];

        foreach ($items as $item) {
            if (in_array($item->uuid, $uuids, true)) {
                throw new InvalidArgumentException("Duplicate uuid in snapshot: {$item->uuid}");
            }
            $uuids[] = $item->uuid;

            if (in_array($item->path, $paths, true)) {
                throw new InvalidArgumentException("Duplicate path in snapshot: {$item->path}");
            }
            $paths[] = $item->path;
        }
    }

    /**
     * Serialize a snapshot item to an array for JSON encoding.
     */
    private function serializeItem(ExtensionSnapshotItem $item): array
    {
        return [
            'uuid' => $item->uuid,
            'path' => $item->path,
            'name' => $item->name,
            'description' => $item->description,
            'creator' => $item->creator,
            'creatorUrl' => $item->creatorUrl,
            'supportedShellVersions' => $item->supportedShellVersions,
            'createdAt' => $item->createdAt,
            'updatedAt' => $item->updatedAt,
            'recentSortValue' => $item->recentSortValue,
            'score' => $item->score,
            'scoreComponents' => $this->serializeScoreComponents($item->scoreComponents),
            'sources' => array_map($this->serializeSource(...), $item->sources),
            'hasScreenshot' => $item->hasScreenshot,
            'trendScore' => $item->trendScore,
        ];
    }

    private function serializeScoreComponents(ScoreComponents $components): array
    {
        return [
            'popularity' => $components->popularity,
            'freshness' => $components->freshness,
        ];
    }

    private function serializeSource(SourceSnapshotItem $source): array
    {
        return [
            'sourceType' => $source->sourceType,
            'externalIdentifier' => $source->externalIdentifier,
            'displayName' => $source->displayName,
            'displayDescription' => $source->displayDescription,
            'displayIcon' => $source->displayIcon,
            'displayScreenshot' => $source->displayScreenshot,
            'supportedShellVersions' => $source->supportedShellVersions,
            'lastCommitAt' => $source->lastCommitAt,
            'lastReleaseAt' => $source->lastReleaseAt,
            'links' => $source->links,
            'metrics' => $source->metrics,
        ];
    }
}
