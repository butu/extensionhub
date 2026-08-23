<?php

namespace App\Repository;

use App\Entity\Extension;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Extension>
 */
class ExtensionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Extension::class);
    }

    /**
     * Find all extensions for the snapshot v2 export, ordered by id.
     *
     * Unlike the retired v1 snapshot, GitHub-only extensions (pk IS NULL)
     * are included: the v2 item contract identifies extensions by uuid, not
     * by EGO pk, so there is no reason to exclude them anymore.
     *
     * @return Extension[]
     */
    public function findAllForSnapshot(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all EGO-backed extensions (pk IS NOT NULL) for the EGO comments
     * sync, ordered by downloads. GitHub-only extensions have no EGO pk to
     * query the EGO comments endpoint with, so they are excluded here.
     *
     * @return Extension[]
     */
    public function findAllEgoForCommentsSync(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.pk IS NOT NULL')
            ->orderBy('e.downloads', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
