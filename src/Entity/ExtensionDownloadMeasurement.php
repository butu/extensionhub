<?php

namespace App\Entity;

use App\Repository\ExtensionDownloadMeasurementRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExtensionDownloadMeasurementRepository::class)]
#[ORM\Table(name: 'extension_download_measurement')]
#[ORM\UniqueConstraint(name: 'uniq_extension_measurement', columns: ['extension_id', 'measured_at'])]
#[ORM\Index(name: 'idx_extension_measured_at', columns: ['extension_id', 'measured_at'])]
class ExtensionDownloadMeasurement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Extension $extension = null;

    /**
     * Nullable until the one-time backfill reassigns legacy rows to their EGO source.
     */
    #[ORM\ManyToOne(targetEntity: ExtensionSource::class)]
    #[ORM\JoinColumn(name: 'source_id', nullable: true)]
    public ?ExtensionSource $source = null;

    #[ORM\Column]
    public int $downloads = 0;

    #[ORM\Column]
    public ?DateTime $measuredAt = null;
}
