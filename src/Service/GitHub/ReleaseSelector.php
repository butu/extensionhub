<?php

namespace App\Service\GitHub;

/**
 * Pure release-asset selection against an already-loaded release list, free
 * of any GitHub HTTP call.
 */
final class ReleaseSelector
{
    /**
     * @param Release[] $releases
     */
    public function selectInstallableRelease(array $releases): ?ReleaseAsset
    {
        $best = null;

        foreach ($releases as $release) {
            $asset = $this->installableAssetOf($release);
            if ($asset === null) {
                continue;
            }

            if ($best === null || $release->publishedAt > $best->publishedAt) {
                $best = $release;
            }
        }

        return $best !== null ? $this->installableAssetOf($best) : null;
    }

    private function installableAssetOf(Release $release): ?ReleaseAsset
    {
        if ($release->draft || $release->prerelease || $release->publishedAt === null) {
            return null;
        }

        foreach ($release->assets as $asset) {
            if ($asset->isZip()) {
                return $asset;
            }
        }

        return null;
    }
}
