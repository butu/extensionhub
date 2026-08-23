<?php

namespace App\Service;

use App\Entity\ExtensionSource;
use RuntimeException;

/**
 * Validates a built extensions snapshot v2 payload against its schema
 * contract, kept pure and dependency-free so the contract has a single
 * home independent of how the payload was produced.
 */
final class SnapshotSchemaValidator
{
    /**
     * Single source of truth for the v2 contract constants; consumers
     * (e.g. ExtensionSnapshotBuilder) alias these rather than redeclaring
     * their own values, to avoid constant drift between builder and
     * validator.
     */
    public const SCHEMA_VERSION = 2;
    public const PAGE_SIZE = 20;

    /**
     * Validate the payload against the v2 schema contract.
     *
     * @param array<string, mixed> $payload
     * @throws RuntimeException if validation fails
     */
    public function validate(array $payload): void
    {
        if (!isset($payload['schemaVersion']) || $payload['schemaVersion'] !== self::SCHEMA_VERSION) {
            throw new RuntimeException("Invalid schemaVersion in snapshot");
        }

        if (!isset($payload['pageSize']) || $payload['pageSize'] !== self::PAGE_SIZE) {
            throw new RuntimeException("Invalid pageSize in snapshot");
        }

        if (!isset($payload['count']) || !isset($payload['items'])) {
            throw new RuntimeException("Missing count or items in snapshot");
        }

        if ($payload['count'] !== count($payload['items'])) {
            throw new RuntimeException("Snapshot count does not match items length");
        }

        $requiredFields = [
            'uuid', 'path', 'name', 'description', 'creator', 'createdAt', 'updatedAt',
            'recentSortValue', 'score', 'scoreComponents', 'sources', 'trendScore',
        ];
        $forbiddenFields = ['pk', 'slug', 'gnomeUrl', 'installUrl'];

        foreach ($payload['items'] as $index => $item) {
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $item) || $item[$field] === null) {
                    throw new RuntimeException("Item {$index} missing required field: {$field}");
                }
            }

            foreach ($forbiddenFields as $field) {
                if (array_key_exists($field, $item)) {
                    throw new RuntimeException("Item {$index} must not contain retired v1 field: {$field}");
                }
            }

            if (!str_starts_with($item['path'], '/extension/')) {
                throw new RuntimeException("Item {$index} has invalid path: {$item['path']}");
            }

            if (!is_int($item['score']) || $item['score'] < 0 || $item['score'] > 100) {
                throw new RuntimeException("Item {$index} has invalid score");
            }

            if (!is_int($item['trendScore']) || $item['trendScore'] < 0 || $item['trendScore'] > 100) {
                throw new RuntimeException("Item {$index} has invalid trendScore");
            }

            $components = $item['scoreComponents'];
            foreach (['popularity', 'freshness'] as $component) {
                if (!array_key_exists($component, $components) || !is_int($components[$component])
                    || $components[$component] < 0 || $components[$component] > 100) {
                    throw new RuntimeException("Item {$index} has invalid scoreComponents.{$component}");
                }
            }

            if (!is_array($item['sources']) || count($item['sources']) < 1) {
                throw new RuntimeException("Item {$index} must have at least one source");
            }

            foreach ($item['sources'] as $sourceIndex => $source) {
                foreach (['sourceType', 'externalIdentifier', 'links', 'metrics'] as $sourceField) {
                    if (!array_key_exists($sourceField, $source)) {
                        throw new RuntimeException("Item {$index} source {$sourceIndex} missing field: {$sourceField}");
                    }
                }

                if (!in_array($source['sourceType'], [ExtensionSource::TYPE_EGO, ExtensionSource::TYPE_GITHUB], true)) {
                    throw new RuntimeException("Item {$index} source {$sourceIndex} has invalid sourceType");
                }
            }
        }
    }
}
