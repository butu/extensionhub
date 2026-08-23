<?php

namespace App\Tests\Service;

use App\Entity\ExtensionSource;
use App\Service\SnapshotSchemaValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the pure, dependency-free v2 snapshot contract: `validate(array
 * $payload): void` throws RuntimeException on any contract violation and
 * returns void silently for a conforming payload.
 */
class SnapshotSchemaValidatorTest extends TestCase
{
    private const SCHEMA_VERSION = SnapshotSchemaValidator::SCHEMA_VERSION;
    private const PAGE_SIZE = SnapshotSchemaValidator::PAGE_SIZE;

    /**
     * @param array<string, mixed> $overrides merged over one known-good item
     */
    private function makeValidItem(array $overrides = []): array
    {
        $item = [
            'uuid' => 'ego-only@example',
            'path' => '/extension/ego-only%40example',
            'name' => 'Ego Extension',
            'description' => 'Ego Extension description',
            'creator' => 'creator-1',
            'createdAt' => '2024-01-01T00:00:00+00:00',
            'updatedAt' => '2025-01-01T00:00:00+00:00',
            'recentSortValue' => '2025-01-01T00:00:00+00:00',
            'score' => 50,
            'scoreComponents' => [
                'popularity' => 40,
                'freshness' => 60,
            ],
            'trendScore' => 0,
            'sources' => [
                [
                    'sourceType' => ExtensionSource::TYPE_EGO,
                    'externalIdentifier' => '100',
                    'links' => [],
                    'metrics' => [],
                ],
            ],
        ];

        return array_replace($item, $overrides);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $overrides merged over the payload envelope
     * @return array<string, mixed>
     */
    private function makePayload(array $items, array $overrides = []): array
    {
        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => '2025-01-01T00:00:00+00:00',
            'count' => count($items),
            'pageSize' => self::PAGE_SIZE,
            'items' => $items,
        ];

        return array_replace($payload, $overrides);
    }

    public function testAcceptsAValidPayloadWithOneConformingItem(): void
    {
        $validator = new SnapshotSchemaValidator();
        $payload = $this->makePayload([$this->makeValidItem()]);

        $validator->validate($payload);

        // No exception means the pure validator accepted the payload as-is.
        $this->addToAssertionCount(1);
    }

    public function testRejectsItemMissingARequiredKey(): void
    {
        $validator = new SnapshotSchemaValidator();
        $item = $this->makeValidItem();
        unset($item['description']);
        $payload = $this->makePayload([$item]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing required field: description');

        $validator->validate($payload);
    }

    public function testRejectsItemContainingForbiddenLegacyKey(): void
    {
        $validator = new SnapshotSchemaValidator();
        $item = $this->makeValidItem(['pk' => 100]);
        $payload = $this->makePayload([$item]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain retired v1 field: pk');

        $validator->validate($payload);
    }

    public function testRejectsPageSizeThatDoesNotMatchTheContractConstant(): void
    {
        $validator = new SnapshotSchemaValidator();
        $payload = $this->makePayload([$this->makeValidItem()], ['pageSize' => 21]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid pageSize in snapshot');

        $validator->validate($payload);
    }

    public function testRejectsCountThatDoesNotMatchTheItemsTotal(): void
    {
        $validator = new SnapshotSchemaValidator();
        $payload = $this->makePayload(
            [$this->makeValidItem(), $this->makeValidItem(['uuid' => 'other@example'])],
            ['count' => 1]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Snapshot count does not match items length');

        $validator->validate($payload);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        $egoSource = [
            'sourceType' => ExtensionSource::TYPE_EGO,
            'externalIdentifier' => '100',
            'links' => [],
            'metrics' => [],
        ];

        $validItem = [
            'uuid' => 'ego-only@example',
            'path' => '/extension/ego-only%40example',
            'name' => 'Ego Extension',
            'description' => 'Ego Extension description',
            'creator' => 'creator-1',
            'createdAt' => '2024-01-01T00:00:00+00:00',
            'updatedAt' => '2025-01-01T00:00:00+00:00',
            'recentSortValue' => '2025-01-01T00:00:00+00:00',
            'score' => 50,
            'scoreComponents' => ['popularity' => 40, 'freshness' => 60],
            'trendScore' => 0,
            'sources' => [$egoSource],
        ];

        $envelope = static fn (array $items): array => [
            'schemaVersion' => SnapshotSchemaValidator::SCHEMA_VERSION,
            'generatedAt' => '2025-01-01T00:00:00+00:00',
            'count' => count($items),
            'pageSize' => SnapshotSchemaValidator::PAGE_SIZE,
            'items' => $items,
        ];

        yield 'missing schemaVersion' => [
            array_diff_key($envelope([$validItem]), ['schemaVersion' => true]),
            'Invalid schemaVersion in snapshot',
        ];

        yield 'wrong schemaVersion value' => [
            array_replace($envelope([$validItem]), ['schemaVersion' => 3]),
            'Invalid schemaVersion in snapshot',
        ];

        yield 'missing count key' => [
            array_diff_key($envelope([$validItem]), ['count' => true]),
            'Missing count or items in snapshot',
        ];

        yield 'missing items key' => [
            array_diff_key($envelope([$validItem]), ['items' => true]),
            'Missing count or items in snapshot',
        ];

        yield 'invalid path' => [
            $envelope([array_replace($validItem, ['path' => '/wrong/path'])]),
            'Item 0 has invalid path',
        ];

        yield 'score not an integer' => [
            $envelope([array_replace($validItem, ['score' => 50.5])]),
            'Item 0 has invalid score',
        ];

        yield 'score below minimum' => [
            $envelope([array_replace($validItem, ['score' => -1])]),
            'Item 0 has invalid score',
        ];

        yield 'score above maximum' => [
            $envelope([array_replace($validItem, ['score' => 101])]),
            'Item 0 has invalid score',
        ];

        yield 'trendScore below minimum' => [
            $envelope([array_replace($validItem, ['trendScore' => -1])]),
            'Item 0 has invalid trendScore',
        ];

        yield 'trendScore above maximum' => [
            $envelope([array_replace($validItem, ['trendScore' => 101])]),
            'Item 0 has invalid trendScore',
        ];

        yield 'scoreComponents missing popularity' => [
            $envelope([array_replace($validItem, ['scoreComponents' => ['freshness' => 60]])]),
            'Item 0 has invalid scoreComponents.popularity',
        ];

        yield 'scoreComponents freshness out of bounds' => [
            $envelope([array_replace($validItem, ['scoreComponents' => ['popularity' => 40, 'freshness' => 101]])]),
            'Item 0 has invalid scoreComponents.freshness',
        ];

        yield 'empty sources array' => [
            $envelope([array_replace($validItem, ['sources' => []])]),
            'Item 0 must have at least one source',
        ];

        yield 'source missing a required field' => [
            $envelope([array_replace($validItem, ['sources' => [array_diff_key($egoSource, ['metrics' => true])]])]),
            'Item 0 source 0 missing field: metrics',
        ];

        yield 'source has invalid sourceType' => [
            $envelope([array_replace($validItem, ['sources' => [array_replace($egoSource, ['sourceType' => 'bogus'])]])]),
            'Item 0 source 0 has invalid sourceType',
        ];
    }

    /**
     * @dataProvider invalidPayloadProvider
     * @param array<string, mixed> $payload
     */
    public function testRejectsEachDistinctValidationBranch(array $payload, string $expectedMessage): void
    {
        $validator = new SnapshotSchemaValidator();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $validator->validate($payload);
    }
}
