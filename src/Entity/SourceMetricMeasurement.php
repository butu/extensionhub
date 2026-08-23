<?php

namespace App\Entity;

use App\Repository\SourceMetricMeasurementRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SourceMetricMeasurementRepository::class)]
#[ORM\Table(name: 'source_metric_measurement')]
#[ORM\UniqueConstraint(name: 'uniq_source_metric_measured_at', columns: ['source_id', 'metric_type', 'measured_at'])]
class SourceMetricMeasurement
{
    public const METRIC_DOWNLOADS = 'downloads';
    public const METRIC_RATING = 'rating';
    public const METRIC_RATING_COUNT = 'rating_count';
    public const METRIC_STARS = 'stars';
    public const METRIC_FORKS = 'forks';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExtensionSource::class)]
    #[ORM\JoinColumn(name: 'source_id', nullable: false, onDelete: 'CASCADE')]
    public ?ExtensionSource $source = null;

    #[ORM\Column(length: 32)]
    public ?string $metricType = null;

    #[ORM\Column(type: Types::FLOAT)]
    public ?float $value = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    public ?DateTime $measuredAt = null;
}
