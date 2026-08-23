<?php

namespace App\Service\GitHub;

use JsonException;

/**
 * Pure metadata.json path/content validation against already-fetched file
 * contents, free of any GitHub HTTP call. Directories are not searched
 * recursively: only the fixed allowed paths, plus a single UUID-named
 * top-level directory shortcut, are considered.
 */
final class MetadataValidator
{
    private const ALLOWED_STATIC_PATHS = [
        'metadata.json',
        'extensions/metadata.json',
        'src/metadata.json',
        'extension/metadata.json',
        'resources/metadata.json',
    ];

    /**
     * @param array<string, string> $fileContentsByPath already-fetched file contents keyed by repository path
     * @param string[]              $topLevelDirectories top-level directory names of the repository
     */
    public function validate(array $fileContentsByPath, array $topLevelDirectories): MetadataValidationResult
    {
        foreach ($this->candidatePaths($topLevelDirectories) as $path) {
            if (!array_key_exists($path, $fileContentsByPath)) {
                continue;
            }

            return $this->validateContentAt($path, $fileContentsByPath[$path]);
        }

        return MetadataValidationResult::skip('metadata_not_found');
    }

    /**
     * Ordered list of metadata.json paths to try for a repository whose top
     * level directories are already known, static paths first. Exposed
     * (rather than kept private) so a later HTTP layer can fetch exactly
     * these paths, in this order, without duplicating the path list.
     *
     * @param string[] $topLevelDirectories
     *
     * @return string[]
     */
    public function candidatePaths(array $topLevelDirectories): array
    {
        $paths = self::ALLOWED_STATIC_PATHS;

        $uuidLikeDirectories = array_values(array_filter($topLevelDirectories, $this->looksLikeUuid(...)));
        if (count($uuidLikeDirectories) === 1) {
            $paths[] = $uuidLikeDirectories[0] . '/metadata.json';
        }

        return $paths;
    }

    private function looksLikeUuid(string $directoryName): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+$/', $directoryName);
    }

    private function validateContentAt(string $path, string $content): MetadataValidationResult
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return MetadataValidationResult::skip('invalid_json', $path);
        }

        if (!is_array($data)) {
            return MetadataValidationResult::skip('invalid_json', $path);
        }

        $uuid = $data['uuid'] ?? null;
        if (!is_string($uuid) || $uuid === '') {
            return MetadataValidationResult::skip('missing_uuid', $path);
        }

        $shellVersion = $data['shell-version'] ?? null;
        if ($this->isEmptyShellVersion($shellVersion)) {
            return MetadataValidationResult::skip('missing_shell_version', $path);
        }

        return MetadataValidationResult::valid(
            $path,
            $uuid,
            $shellVersion,
            $this->optionalString($data['name'] ?? null),
            $this->optionalString($data['description'] ?? null),
        );
    }

    /**
     * `name` and `description` are optional display fields, so a missing,
     * non-string, or blank value becomes null instead of a skip reason.
     */
    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isEmptyShellVersion(mixed $shellVersion): bool
    {
        if (is_array($shellVersion)) {
            return $shellVersion === [];
        }

        if (is_string($shellVersion)) {
            return trim($shellVersion) === '';
        }

        return true;
    }
}
