<?php

namespace App\Repository;

use App\Entity\Extension;
use App\Entity\ExtensionDownloadMeasurement;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExtensionDownloadMeasurement>
 */
class ExtensionDownloadMeasurementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtensionDownloadMeasurement::class);
    }

    public function recordMeasurement(Extension $extension, int $downloads, DateTimeInterface $measuredAt): void
    {
        if ($extension->id === null) {
            return;
        }

        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform()->getName();

        $sql = $platform === 'postgresql'
            ? <<<'SQL'
                INSERT INTO extension_download_measurement (extension_id, measured_at, downloads)
                VALUES (:extensionId, :measuredAt, :downloads)
                ON CONFLICT (extension_id, measured_at)
                DO UPDATE SET downloads = EXCLUDED.downloads
            SQL
            : <<<'SQL'
                INSERT INTO extension_download_measurement (extension_id, measured_at, downloads)
                VALUES (:extensionId, :measuredAt, :downloads)
                ON DUPLICATE KEY UPDATE downloads = VALUES(downloads)
            SQL;

        $connection->executeStatement(
            $sql,
            [
                'extensionId' => $extension->id,
                'measuredAt' => $measuredAt,
                'downloads' => $downloads,
            ],
            [
                'measuredAt' => Types::DATETIME_MUTABLE,
            ]
        );
    }

    public function purgeOlderThan(DateTimeInterface $cutoff): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM extension_download_measurement WHERE measured_at < :cutoff',
            [
                'cutoff' => $cutoff,
            ],
            [
                'cutoff' => Types::DATETIME_MUTABLE,
            ]
        );
    }
}
