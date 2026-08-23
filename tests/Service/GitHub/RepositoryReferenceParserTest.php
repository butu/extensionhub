<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\RepositoryReferenceParser;
use PHPUnit\Framework\TestCase;

/**
 * Pure URL -> owner/repository normalization, covering exactly the
 * canonical shape https://github.com/{owner}/{repository} the EGO
 * homepage check and the targeted import command both rely on.
 */
class RepositoryReferenceParserTest extends TestCase
{
    private RepositoryReferenceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RepositoryReferenceParser();
    }

    /**
     * @dataProvider acceptedUrlProvider
     */
    public function testParsesCanonicalRepositoryUrl(string $url): void
    {
        $reference = $this->parser->parse($url);

        self::assertNotNull($reference, "Expected {$url} to parse.");
        self::assertSame('boerdereinar', $reference->owner);
        self::assertSame('copyous', $reference->repository);
        self::assertSame('boerdereinar/copyous', $reference->fullName());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedUrlProvider(): array
    {
        return [
            'plain' => ['https://github.com/boerdereinar/copyous'],
            'trailing slash' => ['https://github.com/boerdereinar/copyous/'],
            'query string' => ['https://github.com/boerdereinar/copyous?tab=readme'],
            'fragment' => ['https://github.com/boerdereinar/copyous#readme'],
            'trailing slash with query and fragment' => ['https://github.com/boerdereinar/copyous/?tab=readme#top'],
        ];
    }

    /**
     * @dataProvider rejectedUrlProvider
     */
    public function testRejectsAnythingNotACanonicalRepositoryUrl(string $url): void
    {
        self::assertNull($this->parser->parse($url), "Expected {$url} to be rejected.");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'empty string' => [''],
            'not a url' => ['not a url at all'],
            'http instead of https' => ['http://github.com/boerdereinar/copyous'],
            'other forge' => ['https://gitlab.com/boerdereinar/copyous'],
            'www subdomain' => ['https://www.github.com/boerdereinar/copyous'],
            'api subdomain' => ['https://api.github.com/repos/boerdereinar/copyous'],
            'owner only, no repository' => ['https://github.com/boerdereinar'],
            'issue url' => ['https://github.com/boerdereinar/copyous/issues'],
            'tree subdirectory url' => ['https://github.com/boerdereinar/copyous/tree/main'],
            'clone url' => ['https://github.com/boerdereinar/copyous.git'],
            'ssh shortlink' => ['git@github.com:boerdereinar/copyous.git'],
            'userinfo present' => ['https://user:pass@github.com/boerdereinar/copyous'],
            'explicit port' => ['https://github.com:8080/boerdereinar/copyous'],
            'empty owner segment' => ['https://github.com//copyous'],
        ];
    }
}
