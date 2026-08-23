<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\MetadataValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pure metadata.json path/content validation against already-fetched file
 * contents, deliberately tested without any GitHub HTTP call.
 */
class MetadataValidatorTest extends TestCase
{
    private function validMetadataJson(string $uuid = 'plaid@plyply99'): string
    {
        return json_encode(['uuid' => $uuid, 'shell-version' => ['45', '46']], JSON_THROW_ON_ERROR);
    }

    public function testFindsValidMetadataAtRepositoryRoot(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(['metadata.json' => $this->validMetadataJson()], []);

        self::assertTrue($result->valid);
        self::assertNull($result->skipReason);
        self::assertSame('metadata.json', $result->matchedPath);
        self::assertSame('plaid@plyply99', $result->uuid);
        self::assertSame(['45', '46'], $result->shellVersion);
    }

    public function testFindsValidMetadataUnderExtensionsDirectory(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(['extensions/metadata.json' => $this->validMetadataJson()], []);

        self::assertTrue($result->valid);
        self::assertSame('extensions/metadata.json', $result->matchedPath);
    }

    public function testFindsValidMetadataUnderSrcDirectory(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(['src/metadata.json' => $this->validMetadataJson()], []);

        self::assertTrue($result->valid);
        self::assertSame('src/metadata.json', $result->matchedPath);
    }

    public function testFindsValidMetadataUnderExtensionDirectory(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(['extension/metadata.json' => $this->validMetadataJson()], []);

        self::assertTrue($result->valid);
        self::assertSame('extension/metadata.json', $result->matchedPath);
    }

    /**
     * boerdereinar/copyous ships its metadata.json under resources/, a real
     * layout GitHub's own repository tree confirms (see
     * app:import-github-repository boerdereinar/copyous skip evidence).
     */
    public function testFindsValidMetadataUnderResourcesDirectory(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(['resources/metadata.json' => $this->validMetadataJson()], []);

        self::assertTrue($result->valid);
        self::assertSame('resources/metadata.json', $result->matchedPath);
    }

    /**
     * Adding resources/metadata.json as one more fixed static path must not
     * turn into recursively searching everything under resources/: a
     * deeper, unlisted path is still rejected exactly like any other
     * unknown nested location.
     */
    public function testDoesNotRecursivelySearchUnderResourcesDirectory(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(['resources/nested/metadata.json' => $this->validMetadataJson()], []);

        self::assertFalse($result->valid);
        self::assertSame('metadata_not_found', $result->skipReason);
        self::assertNull($result->matchedPath);
    }

    public function testFindsValidMetadataUnderSingleUuidNamedTopLevelDirectory(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(
            ['plaid@plyply99/metadata.json' => $this->validMetadataJson()],
            ['plaid@plyply99'],
        );

        self::assertTrue($result->valid);
        self::assertSame('plaid@plyply99/metadata.json', $result->matchedPath);
    }

    public function testDoesNotUseUuidDirectoryShortcutWhenMultipleCandidateDirectoriesExist(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(
            ['plaid@plyply99/metadata.json' => $this->validMetadataJson()],
            ['plaid@plyply99', 'other@example.org'],
        );

        self::assertFalse($result->valid);
        self::assertSame('metadata_not_found', $result->skipReason);
    }

    public function testOnlyRecursivelyFindableMetadataIsSkippedWithReason(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(
            ['deep/nested/path/metadata.json' => $this->validMetadataJson()],
            [],
        );

        self::assertFalse($result->valid);
        self::assertSame('metadata_not_found', $result->skipReason);
        self::assertNull($result->matchedPath);
    }

    public function testMissingMetadataAltogetherIsSkippedWithReason(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate([], []);

        self::assertFalse($result->valid);
        self::assertSame('metadata_not_found', $result->skipReason);
    }

    public function testInvalidJsonContentIsSkippedWithReason(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(['metadata.json' => '{not valid json'], []);

        self::assertFalse($result->valid);
        self::assertSame('invalid_json', $result->skipReason);
        self::assertSame('metadata.json', $result->matchedPath);
    }

    public function testMetadataMissingUuidIsSkippedWithReason(): void
    {
        $validator = new MetadataValidator();
        $content = json_encode(['shell-version' => ['45']], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $content], []);

        self::assertFalse($result->valid);
        self::assertSame('missing_uuid', $result->skipReason);
    }

    public function testMetadataWithEmptyUuidIsSkippedWithReason(): void
    {
        $validator = new MetadataValidator();
        $content = json_encode(['uuid' => '', 'shell-version' => ['45']], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $content], []);

        self::assertFalse($result->valid);
        self::assertSame('missing_uuid', $result->skipReason);
    }

    public function testMetadataMissingShellVersionKeyIsSkippedWithReason(): void
    {
        $validator = new MetadataValidator();
        $content = json_encode(['uuid' => 'plaid@plyply99'], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $content], []);

        self::assertFalse($result->valid);
        self::assertSame('missing_shell_version', $result->skipReason);
    }

    public function testMetadataWithEmptyShellVersionArrayIsSkippedWithReason(): void
    {
        $validator = new MetadataValidator();
        $content = json_encode(['uuid' => 'plaid@plyply99', 'shell-version' => []], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $content], []);

        self::assertFalse($result->valid);
        self::assertSame('missing_shell_version', $result->skipReason);
    }

    public function testValidMetadataCarriesSelfDeclaredNameAndDescription(): void
    {
        $validator = new MetadataValidator();
        $content = json_encode([
            'name' => 'Liquid Glass',
            'description' => "Applies a translucent, refractive 'liquid glass' effect to the top panel.",
            'uuid' => 'liquid-glass@thinkingcoding1231.gmail.com',
            'shell-version' => ['49', '50'],
        ], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $content], []);

        self::assertTrue($result->valid);
        self::assertSame('Liquid Glass', $result->name);
        self::assertSame("Applies a translucent, refractive 'liquid glass' effect to the top panel.", $result->description);
    }

    public function testNameAndDescriptionAreTrimmed(): void
    {
        $validator = new MetadataValidator();
        $content = json_encode([
            'name' => "  Liquid Glass\n",
            'description' => '  Nice effect  ',
            'uuid' => 'plaid@plyply99',
            'shell-version' => ['45'],
        ], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $content], []);

        self::assertSame('Liquid Glass', $result->name);
        self::assertSame('Nice effect', $result->description);
    }

    /**
     * name/description are optional display fields: their absence must not
     * turn an otherwise valid candidate into a skip.
     */
    public function testMissingBlankOrNonStringNameAndDescriptionBecomeNullWithoutSkipping(): void
    {
        $validator = new MetadataValidator();

        $missing = $validator->validate(['metadata.json' => $this->validMetadataJson()], []);
        self::assertTrue($missing->valid);
        self::assertNull($missing->name);
        self::assertNull($missing->description);

        $blankOrWrongType = json_encode([
            'name' => '   ',
            'description' => ['not', 'a', 'string'],
            'uuid' => 'plaid@plyply99',
            'shell-version' => ['45'],
        ], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $blankOrWrongType], []);
        self::assertTrue($result->valid);
        self::assertNull($result->name);
        self::assertNull($result->description);
    }

    public function testSkippedResultCarriesNoNameOrDescription(): void
    {
        $validator = new MetadataValidator();
        $content = json_encode(['name' => 'Liquid Glass', 'shell-version' => ['45']], JSON_THROW_ON_ERROR);

        $result = $validator->validate(['metadata.json' => $content], []);

        self::assertFalse($result->valid);
        self::assertNull($result->name);
        self::assertNull($result->description);
    }

    public function testStaticAllowedPathsAreCheckedBeforeUuidDirectoryShortcut(): void
    {
        $validator = new MetadataValidator();

        $result = $validator->validate(
            [
                'metadata.json' => $this->validMetadataJson('root@example'),
                'plaid@plyply99/metadata.json' => $this->validMetadataJson('nested@example'),
            ],
            ['plaid@plyply99'],
        );

        self::assertTrue($result->valid);
        self::assertSame('metadata.json', $result->matchedPath);
        self::assertSame('root@example', $result->uuid);
    }
}
