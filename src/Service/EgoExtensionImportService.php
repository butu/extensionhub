<?php

namespace App\Service;

use App\Entity\Extension;
use App\Repository\SourceMetricMeasurementRepository;
use App\Service\GitHub\ApiException;
use App\Service\GitHub\RepositoryReferenceParser;
use App\Service\GitHub\SourcePersister;
use App\Service\GitHub\TargetedRepositoryLoader;
use App\Service\GitHub\TokenProvider;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The EGO cron import, extracted from UpdateExtensionsCommand: requests the
 * paginated EGO extension-query endpoint, maps each entry onto an Extension
 * via {@see EgoExtensionMapper}, persists it, and syncs its EGO
 * ExtensionSource (recording its download metric into
 * source_metric_measurement) via {@see EgoSourceBackfillService}. Pagination
 * stops as soon as a page fails to load (non-200 status, transport error, or
 * invalid JSON) so a missing/out-of-range page is treated exactly like "no
 * more pages" instead of an error.
 */
final class EgoExtensionImportService
{
    public const EXTENSION_QUERY_URL = 'https://extensions.gnome.org/extension-query/';

    private const DEFAULT_SLEEP_MICROSECONDS = 100000;
    private const DEFAULT_MAX_PAGES = 50000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SourceMetricMeasurementRepository $sourceMetricRepository,
        private readonly EgoSourceBackfillService $sourceBackfillService,
        private readonly EgoExtensionMapper $mapper,
        private readonly HttpClientInterface $httpClient,
        private readonly TokenProvider $tokenProvider,
        private readonly RepositoryReferenceParser $repositoryReferenceParser,
        private readonly TargetedRepositoryLoader $targetedRepositoryLoader,
        private readonly SourcePersister $sourcePersister,
        private readonly int $sleepMicroseconds = self::DEFAULT_SLEEP_MICROSECONDS,
        private readonly int $maxPages = self::DEFAULT_MAX_PAGES,
    ) {
    }

    /**
     * Backfill extension.creation_date for rows that never had one set,
     * estimating it from first_version_pk when available. Pure DB
     * maintenance, independent of the EGO request/import below.
     */
    public function backfillMissingCreationDates(): int
    {
        $connection = $this->entityManager->getConnection();
        $rows = $connection->fetchAllAssociative(
            "SELECT id, first_version_pk FROM extension WHERE creation_date IS NULL OR creation_date = '0000-00-00 00:00:00'"
        );

        $now = new DateTime();
        $updatedCount = 0;
        foreach ($rows as $row) {
            $creationDate = isset($row['first_version_pk']) && $row['first_version_pk'] !== null
                ? Extension::nonFutureDate(Extension::estimateDateFromPk((int) $row['first_version_pk']), null, $now)
                : $now;

            $updatedCount += $connection->executeStatement(
                'UPDATE extension SET creation_date = :creationDate WHERE id = :id',
                [
                    'creationDate' => $creationDate->format('Y-m-d H:i:s'),
                    'id' => (int) $row['id'],
                ]
            );
        }

        return $updatedCount;
    }

    /**
     * Requests every EGO extension-query page, maps/persists each entry,
     * syncs its EGO source (which records its download measurement into
     * source_metric_measurement), and finally purges source-metric
     * measurements past their retention window. $onExtensionUpdated, when
     * given, is invoked with the name of every successfully processed
     * extension in request order (used by the command for its live
     * per-extension console output).
     *
     * @param callable(string): void|null $onExtensionUpdated
     */
    public function importAll(DateTimeInterface $runMeasuredAt, ?callable $onExtensionUpdated = null): EgoExtensionImportResult
    {
        $extensionRepository = $this->entityManager->getRepository(Extension::class);
        $updatedCount = 0;

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $data = $this->fetchPage($page);
            if ($data === null) {
                break;
            }

            foreach ($data['extensions'] ?? [] as $extensionData) {
                /** @var Extension|null $extension */
                $extension = $extensionRepository->findOneBy(['link' => $extensionData['link']]);

                $isNewExtension = false;
                if (!$extension) {
                    $extension = new Extension();
                    $isNewExtension = true;
                }

                $this->mapper->mapDataToEntity($extension, $extensionData, $isNewExtension, $runMeasuredAt);
                $this->entityManager->persist($extension);
                $this->entityManager->flush();

                $skipReason = $this->sourceBackfillService->syncExtension($extension, $runMeasuredAt);
                if ($skipReason === null) {
                    // Only run once the EGO source exists, so persisting a
                    // GitHub candidate never overwrites EGO's display fields.
                    $this->checkGithubRepositoryReference($extension, $runMeasuredAt);
                }

                $updatedCount++;
                if ($onExtensionUpdated !== null) {
                    $onExtensionUpdated($extension->name);
                }
            }

            // wait for a few ms so we don't overload the server
            if ($this->sleepMicroseconds > 0) {
                usleep($this->sleepMicroseconds);
            }
        }

        $metricsCutoff = SourceMetricMeasurementRepository::retentionCutoff(new DateTime());
        $purgedSourceMetricMeasurements = $this->sourceMetricRepository->purgeOlderThan($metricsCutoff);

        // extension_download_measurement was dropped (Version20260901020000);
        // there is nothing left in it to purge.
        return new EgoExtensionImportResult($updatedCount, 0, $purgedSourceMetricMeasurements);
    }

    /**
     * Attaches a GitHub source when the EGO homepage is a canonical GitHub
     * URL reporting the same UUID. Every failure — including a rate-limited
     * ApiException, or a raw Symfony HTTP-client exception that escapes
     * ApiClient's own wrapping when a response throws during cleanup — is a
     * silent skip; one EGO page covers many extensions.
     */
    private function checkGithubRepositoryReference(Extension $extension, DateTimeInterface $now): void
    {
        if ($extension->sourceUrl === null) {
            return;
        }

        $reference = $this->repositoryReferenceParser->parse($extension->sourceUrl);
        if ($reference === null) {
            return;
        }

        $token = $this->tokenProvider->getToken();
        if ($token === null) {
            return;
        }

        try {
            $result = $this->targetedRepositoryLoader->load($token, $reference->owner, $reference->repository);
        } catch (ApiException | HttpClientExceptionInterface) {
            return;
        }

        if (!$result->success) {
            return;
        }

        if ($result->candidate->uuid !== $extension->uuid) {
            // Never attach to, nor spawn a GitHub-only extension for, a
            // repository whose metadata.json reports a different UUID than
            // the EGO extension that triggered this check.
            return;
        }

        $this->sourcePersister->persistCandidate($result->candidate, $now);
    }

    /**
     * @return array{extensions?: array<int, array<string, mixed>>}|null null once a page fails to load
     *         (non-200 status, transport error, or invalid JSON), signalling the caller to stop pagination.
     */
    private function fetchPage(int $page): ?array
    {
        $url = self::EXTENSION_QUERY_URL . '?page=' . $page;

        try {
            $response = $this->httpClient->request('GET', $url);
            $statusCode = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            return null;
        }

        if ($statusCode !== 200) {
            return null;
        }

        try {
            $data = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
