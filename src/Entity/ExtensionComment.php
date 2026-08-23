<?php

namespace App\Entity;

use App\Repository\ExtensionCommentRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExtensionCommentRepository::class)]
#[ORM\Table(name: 'extension_comment')]
#[ORM\UniqueConstraint(name: 'uniq_ext_author_date', columns: ['extension_id', 'author_username', 'comment_date'])]
#[ORM\Index(name: 'idx_extension_comment_ext', columns: ['extension_id'])]
class ExtensionComment
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

    #[ORM\Column(length: 255)]
    public ?string $authorUsername = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $authorUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $gravatar = null;

    #[ORM\Column(type: Types::TEXT)]
    public ?string $comment = null;

    #[ORM\Column]
    public ?int $rating = null;

    #[ORM\Column]
    public bool $isExtensionCreator = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    public ?DateTime $commentDate = null;
}
