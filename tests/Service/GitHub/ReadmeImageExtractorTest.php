<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\ReadmeImageExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Pure README image extraction, deliberately tested without any HTTP call.
 */
class ReadmeImageExtractorTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private function extract(string $markdown, string $readmePath = 'README.md'): array
    {
        return (new ReadmeImageExtractor())->extract($markdown, $readmePath, 'ryohsuke1231', 'liquid-glass', self::SHA);
    }

    private function rawUrl(string $path): string
    {
        return 'https://raw.githubusercontent.com/ryohsuke1231/liquid-glass/' . self::SHA . '/' . $path;
    }

    public function testResolvesRelativeMarkdownImageAgainstTheCommitSha(): void
    {
        $urls = $this->extract('![Dash to Dock Screenshot](assets/demo2.png)');

        self::assertSame([$this->rawUrl('assets/demo2.png')], $urls);
    }

    public function testPreservesReadmeReadingOrder(): void
    {
        $markdown = <<<'MD'
            # Liquid Glass
            ![first](assets/demo2.png)
            ![second](assets/demo3.png)
            ![third](assets/demo4.png)
            MD;

        self::assertSame([
            $this->rawUrl('assets/demo2.png'),
            $this->rawUrl('assets/demo3.png'),
            $this->rawUrl('assets/demo4.png'),
        ], $this->extract($markdown));
    }

    public function testExtractsHtmlImageTagsWithQuotedAndUnquotedSrc(): void
    {
        $markdown = '<p><img src="a.png" width="600"><img src=\'b.png\'><img alt="x" src=c.png></p>';

        self::assertSame([
            $this->rawUrl('a.png'),
            $this->rawUrl('b.png'),
            $this->rawUrl('c.png'),
        ], $this->extract($markdown));
    }

    public function testKeepsAbsoluteUrlsVerbatimForLaterHostValidation(): void
    {
        $markdown = '![badge](https://img.shields.io/badge/License-MIT-blue.svg)';

        self::assertSame(['https://img.shields.io/badge/License-MIT-blue.svg'], $this->extract($markdown));
    }

    public function testRewritesBlobAndRawPageUrlsToTheRawContentHost(): void
    {
        $markdown = <<<'MD'
            ![blob](https://github.com/owner/repo/blob/main/docs/shot.png?raw=true)
            ![raw](https://github.com/owner/repo/raw/v1.2.3/docs/other.png)
            MD;

        self::assertSame([
            'https://raw.githubusercontent.com/owner/repo/main/docs/shot.png',
            'https://raw.githubusercontent.com/owner/repo/v1.2.3/docs/other.png',
        ], $this->extract($markdown));
    }

    /**
     * A README that documents markdown syntax inside a fenced block is not
     * advertising a screenshot.
     */
    public function testIgnoresImagesInsideFencedCodeBlocks(): void
    {
        $markdown = <<<'MD'
            ![real](assets/real.png)

            ```markdown
            ![example](assets/example.png)
            ```

            ~~~
            <img src="assets/tilde-fenced.png">
            ~~~
            MD;

        self::assertSame([$this->rawUrl('assets/real.png')], $this->extract($markdown));
    }

    public function testResolvesRelativePathsAgainstTheReadmeDirectory(): void
    {
        $urls = $this->extract('![shot](img/shot.png)', 'docs/README.md');

        self::assertSame([$this->rawUrl('docs/img/shot.png')], $urls);
    }

    public function testResolvesDotAndDoubleDotSegments(): void
    {
        $urls = $this->extract('![a](./img/a.png)![b](../shared/b.png)', 'docs/README.md');

        self::assertSame([
            $this->rawUrl('docs/img/a.png'),
            $this->rawUrl('shared/b.png'),
        ], $urls);
    }

    public function testRootRelativePathIsResolvedFromTheRepositoryRoot(): void
    {
        $urls = $this->extract('![shot](/assets/shot.png)', 'docs/README.md');

        self::assertSame([$this->rawUrl('assets/shot.png')], $urls);
    }

    public function testRejectsPathsClimbingAboveTheRepositoryRoot(): void
    {
        self::assertSame([], $this->extract('![escape](../../../etc/passwd)'));
    }

    public function testSkipsDataUrisAndPureFragments(): void
    {
        $markdown = '![inline](data:image/png;base64,AAAA)![anchor](#section)';

        self::assertSame(['data:image/png;base64,AAAA'], $this->extract($markdown));
    }

    public function testDeduplicatesRepeatedReferencesKeepingFirstPosition(): void
    {
        $markdown = '![a](assets/a.png)![b](assets/b.png)![a again](assets/a.png)';

        self::assertSame([
            $this->rawUrl('assets/a.png'),
            $this->rawUrl('assets/b.png'),
        ], $this->extract($markdown));
    }

    public function testHandlesAngleBracketedAndTitledMarkdownUrls(): void
    {
        $markdown = '![a](<assets/with space.png>)![b](assets/b.png "A title")';

        self::assertSame([
            $this->rawUrl('assets/with%20space.png'),
            $this->rawUrl('assets/b.png'),
        ], $this->extract($markdown));
    }

    public function testProtocolRelativeUrlBecomesHttps(): void
    {
        self::assertSame(['https://example.org/x.png'], $this->extract('![x](//example.org/x.png)'));
    }

    public function testReadmeWithoutImagesYieldsNoCandidates(): void
    {
        self::assertSame([], $this->extract("# Title\n\nJust prose and a [link](https://example.org)."));
    }
}
