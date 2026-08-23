<?php

namespace App\Entity;

use App\Repository\ExtensionRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExtensionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_extension_uuid', columns: ['uuid'])]
class Extension
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public ?string $name = null;

    #[ORM\Column(length: 255)]
    public ?string $link = null;

    #[ORM\Column(length: 255)]
    public ?string $icon = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $screenshot = null;

    #[ORM\Column(nullable: true)]
    public ?int $downloads = null;

    #[ORM\Column(length: 255)]
    public ?string $creator = null;

    #[ORM\Column(length: 255)]
    public ?string $creator_url = null;

    #[ORM\Column(length: 255)]
    public ?string $uuid = null;

    /**
     * The EGO primary key. Null for GitHub-only extensions that have no EGO
     * counterpart; such extensions are excluded from the v1 snapshot/comments
     * until the v2 schema can represent them without a pk-shaped identifier.
     */
    #[ORM\Column(nullable: true)]
    public ?int $pk = null;

    #[ORM\Column(type: Types::TEXT)]
    public ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    public ?DateTime $creationDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    public ?DateTime $lastChange = null;

    #[ORM\Column(nullable: true)]
    public ?float $rating = null;

    #[ORM\Column(nullable: true)]
    public ?int $comments = null;

    /**
     * The highest version PK from shell_version_map - used to determine when the extension was last updated.
     * Higher PKs = more recent updates. PKs are global and sequential across all extensions.
     */
    #[ORM\Column(nullable: true)]
    public ?int $latestVersionPk = null;

    /**
     * The lowest version PK from shell_version_map - used to determine when the extension was first created.
     */
    #[ORM\Column(nullable: true)]
    public ?int $firstVersionPk = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $supportedShellVersions = null;

    public function getComments(): ?int
    {
        return $this->comments;
    }

    public function setComments(?int $comments): static
    {
        $this->comments = $comments;

        return $this;
    }

    public function getScore(array $searchterms = []): int
    {
        $score = 0;
        if ($this->rating !== null) {
            $score += $this->rating * 10;
        }
        if ($this->downloads) {
            $score += $this->downloads / 1000;
        }
        $score *= $this->comments / 10;

        // if the name contains all the search terms, add points
        foreach ($searchterms as $term) {
            if (stripos($this->name, $term) !== false) {
                $score += 1000;
            }
        }

        // if the title contains any of the search terms as a whole word, add 100 points
        foreach ($searchterms as $term) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $this->name)) {
                $score += 5000;
            }
        }

        // if the extension has a screenshot, add 50 points
        if ($this->screenshot) {
            $score += 50;
        }

        // if the extension has a description, add 50 points
        if ($this->description) {
            $score += 50;
        }

        // if the name or description contains "not maintained" or "deprecated" or "abandoned" or "unmaintained", subtract 1000 points
//        if (stripos($this->name, 'not maintained') !== false || stripos($this->description,
//                'not maintained') !== false ||
//            stripos($this->name, 'deprecated') !== false || stripos($this->description, 'deprecated') !== false ||
//            stripos($this->name, 'abandoned') !== false || stripos($this->description, 'abandoned') !== false ||
//            stripos($this->name, 'unmaintained') !== false || stripos($this->description, 'unmaintained') !== false) {
//            $score -= 2000;
//        }

        return $score;
    }

    /**
     * Estimate the date when this extension was last updated based on the latestVersionPk.
     * 
     * Reference points (from GNOME Extensions review pages):
     * - PK 55000 → 2024-05-20
     * - PK 64995 → 2025-09-09
     * 
     * This gives us approximately 625 PKs per month or ~20 PKs per day.
     */
    public function getEstimatedLastUpdate(): ?DateTime
    {
        if ($this->latestVersionPk === null) {
            return $this->lastChange;
        }

        return self::estimateDateFromPk($this->latestVersionPk);
    }

    /**
     * Estimate the date when this extension was first created based on the firstVersionPk.
     */
    public function getEstimatedCreationDate(): ?DateTime
    {
        if ($this->firstVersionPk === null) {
            return $this->creationDate;
        }

        return self::estimateDateFromPk($this->firstVersionPk);
    }

    /**
     * Convert a version PK to an estimated date.
     * 
     * Using linear interpolation between known reference points:
     * - PK 55000 → 2024-05-20 (timestamp: 1716163200)
     * - PK 64995 → 2025-09-09 (timestamp: 1757376000)
     * 
     * Formula: date = referenceDate + (pk - referencePk) * secondsPerPk
     *
     * Pure extrapolation; it does not know "now" and can overshoot into the
     * future for a preallocated (very recent) PK — see nonFutureDate().
     */
    public static function estimateDateFromPk(int $pk): DateTime
    {
        // Reference point: PK 55000 = 2024-05-20
        $referencePk = 55000;
        $referenceTimestamp = 1716163200; // 2024-05-20 00:00:00 UTC

        // Calculated: ~9995 PKs over ~477 days (2024-05-20 to 2025-09-09)
        // That's approximately 20.96 PKs per day, or 4120 seconds per PK
        $secondsPerPk = 4120;

        $estimatedTimestamp = $referenceTimestamp + (($pk - $referencePk) * $secondsPerPk);

        $date = new DateTime();
        $date->setTimestamp($estimatedTimestamp);

        return $date;
    }

    /**
     * Never let a future PK-date estimate stand as a real date: prefer
     * $knownDate when it is itself non-future, otherwise fall back to the
     * epoch as the "old, unknown" sentinel (never "now" - that would still
     * inflate recency/freshness). The epoch must never reach public output
     * as a literal 1970 date; callers that serialize it are responsible for
     * treating it as "unknown" (see ExtensionSnapshotMapper).
     */
    public static function nonFutureDate(DateTime $estimated, ?DateTimeInterface $knownDate, DateTimeInterface $now): DateTime
    {
        if ($estimated->getTimestamp() <= $now->getTimestamp()) {
            return $estimated;
        }

        if ($knownDate !== null && $knownDate->getTimestamp() <= $now->getTimestamp()) {
            return DateTime::createFromInterface($knownDate);
        }

        return (new DateTime())->setTimestamp(0);
    }

    /**
     * Check if this extension was updated within a certain time range based on latestVersionPk.
     */
    public function wasUpdatedWithinMonths(int $months): bool
    {
        $estimatedDate = $this->getEstimatedLastUpdate();
        if ($estimatedDate === null) {
            return false;
        }

        $cutoffDate = new DateTime("-{$months} months");
        return $estimatedDate >= $cutoffDate;
    }
}
