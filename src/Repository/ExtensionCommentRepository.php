<?php

namespace App\Repository;

use App\Entity\ExtensionComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExtensionComment>
 */
class ExtensionCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtensionComment::class);
    }

    /**
     * Find an existing comment by extension, author, and date (composite unique key).
     */
    public function findByCompositeKey(int $extensionId, string $authorUsername, \DateTime $commentDate): ?ExtensionComment
    {
        return $this->createQueryBuilder('c')
            ->where('c.extension = :extensionId')
            ->andWhere('c.authorUsername = :authorUsername')
            ->andWhere('c.commentDate = :commentDate')
            ->setParameter('extensionId', $extensionId)
            ->setParameter('authorUsername', $authorUsername)
            ->setParameter('commentDate', $commentDate)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all comments grouped by canonical extension uuid for the
     * snapshot v2 comments export. Only includes comments with rating > 0.
     *
     * Comments are only ever synced for EGO-backed extensions (see
     * ExtensionRepository::findAllEgoForCommentsSync()), so GitHub-only
     * extensions naturally end up with an empty comment list rather than
     * fabricated entries.
     *
     * @return array<string, ExtensionComment[]> Keyed by extension uuid
     */
    public function findAllGroupedByExtensionUuid(): array
    {
        /** @var ExtensionComment[] $comments */
        $comments = $this->createQueryBuilder('c')
            ->join('c.extension', 'e')
            ->where('c.rating > 0')
            ->orderBy('c.commentDate', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($comments as $comment) {
            $uuid = $comment->extension?->uuid;
            if ($uuid === null || $uuid === '') {
                continue;
            }

            if (!isset($grouped[$uuid])) {
                $grouped[$uuid] = [];
            }
            $grouped[$uuid][] = $comment;
        }

        return $grouped;
    }

    /**
     * Remove comments older than the given cutoff date for a specific extension.
     */
    public function removeOlderThan(int $extensionId, \DateTime $cutoffDate): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.extension = :extensionId')
            ->andWhere('c.commentDate < :cutoffDate')
            ->setParameter('extensionId', $extensionId)
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}
