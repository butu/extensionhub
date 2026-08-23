<?php

namespace App\Dto;

/**
 * Data Transfer Object for a single comment in the public comments snapshot.
 */
final class CommentSnapshotItem
{
    public function __construct(
        public string $authorUsername,
        public ?string $authorUrl,
        public ?string $gravatar,
        public string $comment,
        public int $rating,
        public bool $isExtensionCreator,
        public string $commentDate,
    ) {}
}
