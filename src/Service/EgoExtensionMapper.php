<?php

namespace App\Service;

use App\Entity\Extension;
use DateTime;
use DateTimeInterface;

/**
 * Pure EGO extension-query-payload -> Extension mapping logic, free of any
 * HTTP or Doctrine persistence call so it stays testable without a database
 * connection or a live EGO request.
 */
final class EgoExtensionMapper
{
    /**
     * @param array<string, mixed> $extensionData raw decoded EGO extension-query item
     */
    public function mapDataToEntity(
        Extension $extension,
        array $extensionData,
        bool $isNewExtension,
        ?DateTimeInterface $now = null
    ): void {
        $now = $now ?? new DateTime();

        $extension->uuid = $extensionData['uuid'];
        $extension->name = $extensionData['name'];
        $extension->creator = $extensionData['creator'] ?? null;
        $extension->creator_url = $extensionData['creator_url'] ?? null;
        $extension->pk = $extensionData['pk'] ?? null;
        $extension->description = $extensionData['description'] ?? null;
        $extension->link = $extensionData['link'];
        $extension->icon = $extensionData['icon'];
        $extension->screenshot = $extensionData['screenshot'] ?? null;
        $extension->downloads = $extensionData['downloads'] ?? null;
        $extension->sourceUrl = $extensionData['url'] ?? null;
        $extension->supportedShellVersions = $this->extractSupportedShellVersions($extensionData['shell_version_map'] ?? []);

        // Extract version PKs from shell_version_map to determine real creation/update dates
        $versionPks = $this->extractVersionPks($extensionData['shell_version_map'] ?? []);
        if (!empty($versionPks)) {
            $extension->latestVersionPk = max($versionPks);
            $extension->firstVersionPk = min($versionPks);

            // EGO preallocates version-map PKs ahead of their actual use,
            // so the estimate can overshoot into the future for a very
            // recent PK; nonFutureDate() keeps it real (see its docblock).
            $extension->lastChange = Extension::nonFutureDate(
                Extension::estimateDateFromPk($extension->latestVersionPk),
                $extension->lastChange,
                $now
            );

            if ($isNewExtension) {
                $extension->creationDate = DateTime::createFromInterface($now);
            } elseif ($extension->creationDate === null) {
                $extension->creationDate = Extension::nonFutureDate(
                    Extension::estimateDateFromPk($extension->firstVersionPk),
                    null,
                    $now
                );
            }
        } else {
            // Fallback for extensions without shell_version_map
            if ($extension->creationDate === null) {
                $extension->creationDate = DateTime::createFromInterface($now);
            }
            $extension->lastChange = DateTime::createFromInterface($now);
        }
    }

    /**
     * Extract all version PKs from the shell_version_map.
     *
     * Example shell_version_map:
     * {
     *   "45": {"pk": 64995, "version": 102},
     *   "46": {"pk": 64995, "version": 102},
     *   "3.38": {"pk": 19642, "version": 69}
     * }
     *
     * @return int[]
     */
    private function extractVersionPks(array $shellVersionMap): array
    {
        $pks = [];
        foreach ($shellVersionMap as $versionInfo) {
            if (isset($versionInfo['pk']) && is_int($versionInfo['pk'])) {
                $pks[] = $versionInfo['pk'];
            }
        }
        return array_unique($pks);
    }

    /**
     * Extract all currently supported GNOME Shell versions from shell_version_map keys.
     *
     * @return string[]|null
     */
    private function extractSupportedShellVersions(array $shellVersionMap): ?array
    {
        if ($shellVersionMap === []) {
            return null;
        }

        $versions = array_map('strval', array_keys($shellVersionMap));
        $versions = array_values(array_unique($versions));
        sort($versions, SORT_NATURAL);

        return $versions;
    }
}
