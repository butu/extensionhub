<?php

namespace App\Entity;

use App\Repository\ExtensionSourceRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExtensionSourceRepository::class)]
#[ORM\Table(name: 'extension_source')]
#[ORM\UniqueConstraint(name: 'uniq_source_type_external_id', columns: ['source_type', 'external_identifier'])]
#[ORM\UniqueConstraint(name: 'uniq_extension_source_type', columns: ['extension_id', 'source_type'])]
class ExtensionSource
{
    public const TYPE_EGO = 'ego';
    public const TYPE_GITHUB = 'github';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Extension $extension = null;

    #[ORM\Column(length: 32)]
    public ?string $sourceType = null;

    #[ORM\Column(length: 255)]
    public ?string $externalIdentifier = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $sourceUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $installUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $displayName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $displayDescription = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $displayIcon = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $displayScreenshot = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $supportedShellVersions = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?DateTime $lastCommitAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?DateTime $lastReleaseAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    public ?DateTime $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    public ?DateTime $updatedAt = null;
}
