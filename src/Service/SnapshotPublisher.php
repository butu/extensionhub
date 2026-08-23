<?php

namespace App\Service;

use RuntimeException;

/**
 * Atomic single-file publish primitive shared by every snapshot writer:
 * write-to-temp, optional read-back validation, rename-into-place, optional
 * byte-identical alias copy.
 */
final class SnapshotPublisher
{
    public function __construct(
        private string $projectDir,
    ) {}

    /**
     * Write $json atomically to "{$this->projectDir}/{$relativeTargetPath}".
     *
     * When $relativeAliasPath is given, additionally produces a
     * byte-identical copy at "{$this->projectDir}/{$relativeAliasPath}"
     * once the primary file has been published.
     *
     * When $validate is given, it is invoked with the bytes read back from
     * the temporary file (and the temporary file's path) before the rename
     * into place. The callback is responsible for throwing on invalid
     * content and, if desired, removing the temporary file itself
     * (mirroring each call site's original error-handling behavior); this
     * class does not unlink on validation failure on its own.
     *
     * @param (callable(string $written, string $temporaryPath): void)|null $validate
     * @throws RuntimeException if the temp write, rename, or alias copy fails
     */
    public function publish(
        string $relativeTargetPath,
        string $json,
        ?string $relativeAliasPath = null,
        ?callable $validate = null,
    ): void {
        $targetPath = $this->projectDir . '/' . $relativeTargetPath;
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $temporaryPath = $targetPath . '.tmp';
        $bytesWritten = file_put_contents($temporaryPath, $json);
        if ($bytesWritten === false) {
            throw new RuntimeException("Cannot write to temporary path: {$temporaryPath}");
        }

        if ($validate !== null) {
            $written = file_get_contents($temporaryPath);
            $validate($written, $temporaryPath);
        }

        if (!rename($temporaryPath, $targetPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Cannot rename {$temporaryPath} to {$targetPath}");
        }

        if ($relativeAliasPath !== null) {
            $aliasPath = $this->projectDir . '/' . $relativeAliasPath;
            if (!copy($targetPath, $aliasPath)) {
                throw new RuntimeException("Cannot create versioned alias at {$aliasPath}");
            }
        }
    }
}
