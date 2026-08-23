<?php

namespace App\Repository;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExtensionSource>
 */
class ExtensionSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtensionSource::class);
    }

    public function findOneByTypeAndExternalIdentifier(string $sourceType, string $externalIdentifier): ?ExtensionSource
    {
        return $this->findOneBy([
            'sourceType' => $sourceType,
            'externalIdentifier' => $externalIdentifier,
        ]);
    }

    public function findOneByExtensionAndType(Extension $extension, string $sourceType): ?ExtensionSource
    {
        return $this->findOneBy([
            'extension' => $extension,
            'sourceType' => $sourceType,
        ]);
    }

    /**
     * All already-persisted GitHub sources, for the refresh run to re-fetch.
     *
     * @return ExtensionSource[]
     */
    public function findAllGithubSourcesForRefresh(): array
    {
        return $this->findBy(['sourceType' => ExtensionSource::TYPE_GITHUB], ['id' => 'ASC']);
    }

    /**
     * All sources for the given extensions, grouped by extension id, for
     * batch-loading in the snapshot builder (avoids one query per extension).
     *
     * @param int[] $extensionIds
     * @return array<int, ExtensionSource[]> keyed by extension id
     */
    public function findAllGroupedByExtensionIds(array $extensionIds): array
    {
        if ($extensionIds === []) {
            return [];
        }

        /** @var ExtensionSource[] $sources */
        $sources = $this->createQueryBuilder('s')
            ->where('s.extension IN (:extensionIds)')
            ->setParameter('extensionIds', $extensionIds)
            ->orderBy('s.sourceType', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($sources as $source) {
            $extensionId = $source->extension?->id;
            if ($extensionId === null) {
                continue;
            }

            $grouped[$extensionId][] = $source;
        }

        return $grouped;
    }
}
