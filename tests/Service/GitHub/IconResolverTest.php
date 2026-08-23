<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\ApiCache;
use App\Service\GitHub\ApiClient;
use App\Service\GitHub\ApiException;
use App\Service\GitHub\IconResolver;
use App\Service\GitHub\ImageProbe;
use App\Service\GitHub\ImageValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Icon/logo selection directly from the repository's own file layout,
 * tested exclusively against a MockHttpClient: no live GitHub or raw-content
 * request is made.
 */
class IconResolverTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-icon-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function resolver(MockHttpClient $httpClient): IconResolver
    {
        $apiClient = new ApiClient($httpClient, new ApiCache(new ParameterBag(['kernel.project_dir' => $this->cacheDir])));
        $validator = new ImageValidator();

        return new IconResolver($apiClient, new ImageProbe($httpClient, $validator), $validator);
    }

    private function rawUrl(string $path): string
    {
        return 'https://raw.githubusercontent.com/owner/repo/' . self::SHA . '/' . $path;
    }

    private function commitsResponse(string $sha = self::SHA): JsonMockResponse
    {
        return new JsonMockResponse([['sha' => $sha]]);
    }

    /**
     * @param array<int, array{name: string, type: string}> $items
     */
    private function listingResponse(array $items): JsonMockResponse
    {
        return new JsonMockResponse($items);
    }

    private function file(string $name): array
    {
        return ['name' => $name, 'type' => 'file'];
    }

    private function dir(string $name): array
    {
        return ['name' => $name, 'type' => 'dir'];
    }

    private function notFoundResponse(): MockResponse
    {
        return new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);
    }

    private function svgBytes(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="#fff"/></svg>';
    }

    private function pngBytes(int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function gifBytes(int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagegif($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * The HEAD + GET pair the probe performs for one raster candidate.
     *
     * @return MockResponse[]
     */
    private function rasterResponses(string $bytes, string $contentType): array
    {
        return [
            new MockResponse('', ['response_headers' => [
                'content-type' => $contentType,
                'content-length' => (string) strlen($bytes),
            ]]),
            new MockResponse($bytes),
        ];
    }

    /**
     * The HEAD + GET pair the probe performs for an SVG candidate. The URL
     * must be pinned to the commit SHA to satisfy the immutability rule.
     *
     * @return MockResponse[]
     */
    private function svgResponses(string $svg): array
    {
        return [
            new MockResponse('', ['response_headers' => [
                'content-type' => 'image/svg+xml',
                'content-length' => (string) strlen($svg),
            ]]),
            new MockResponse($svg),
        ];
    }

    public function testPicksLogoSvgAtRepositoryRootWhenPresent(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->file('logo.svg'), $this->file('README.md')]),
            ...$this->svgResponses($this->svgBytes()),
        ]);

        self::assertSame($this->rawUrl('logo.svg'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    public function testPrefersLogoSvgOverLogoPngInTheSameDirectory(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->file('logo.svg'), $this->file('logo.png')]),
            ...$this->svgResponses($this->svgBytes()),
        ]);

        self::assertSame($this->rawUrl('logo.svg'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    public function testFallsBackToLogoPngWhenNoSvgIsPresent(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->file('logo.png')]),
            ...$this->rasterResponses($this->pngBytes(), 'image/png'),
        ]);

        self::assertSame($this->rawUrl('logo.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * GIF must be accepted as a valid icon/logo raster format.
     */
    public function testAcceptsAGifIconWhenNoOtherCandidateExists(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->file('icon.gif')]),
            ...$this->rasterResponses($this->gifBytes(), 'image/gif'),
        ]);

        self::assertSame($this->rawUrl('icon.gif'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    public function testPrefersLogoOverIconWhenBothArePresent(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->file('icon.png'), $this->file('logo.png')]),
            ...$this->rasterResponses($this->pngBytes(), 'image/png'),
        ]);

        self::assertSame($this->rawUrl('logo.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * No icon/logo file at the root and none of the known layout
     * directories present: the lookup stops after the root listing.
     */
    public function testReturnsNullWhenRootHasNoIconAndNoKnownSubdirectory(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->file('README.md'), $this->dir('docs')]),
        ]);

        self::assertNull($this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * A directory is only ever listed when the root listing already
     * reports it exists: "extension" is absent here, so only "extensions"
     * and "src" are checked, in that fixed order.
     */
    public function testChecksKnownLayoutDirectoriesInOrderWhenPresentAtRoot(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->dir('extensions'), $this->dir('src')]),
            $this->listingResponse([$this->file('logo.png')]), // extensions/
            ...$this->rasterResponses($this->pngBytes(), 'image/png'),
        ]);

        self::assertSame(
            $this->rawUrl('extensions/logo.png'),
            $this->resolver($httpClient)->resolve('token', 'owner', 'repo'),
        );
    }

    public function testFallsThroughToSrcWhenExtensionsHasNoIcon(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->dir('extensions'), $this->dir('src')]),
            $this->listingResponse([$this->file('README.md')]), // extensions/, no icon
            $this->listingResponse([$this->file('icon.png')]), // src/
            ...$this->rasterResponses($this->pngBytes(), 'image/png'),
        ]);

        self::assertSame(
            $this->rawUrl('src/icon.png'),
            $this->resolver($httpClient)->resolve('token', 'owner', 'repo'),
        );
    }

    /**
     * The GNOME extension UUID directory ("name@domain") is checked last,
     * and only when exactly one such directory exists at the top level.
     */
    public function testUsesTheSingleUuidNamedTopLevelDirectoryAsALastResort(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([$this->dir('myextension@example.com'), $this->file('README.md')]),
            $this->listingResponse([$this->file('logo.svg')]), // myextension@example.com/
            ...$this->svgResponses($this->svgBytes()),
        ]);

        self::assertSame(
            // The '@' in the directory name is percent-encoded, matching
            // the same convention already used for URL path segments
            // elsewhere in the GitHub import (see ReadmeImageExtractor).
            $this->rawUrl('myextension%40example.com/logo.svg'),
            $this->resolver($httpClient)->resolve('token', 'owner', 'repo'),
        );
    }

    /**
     * More than one UUID-like top-level directory is ambiguous, so none of
     * them is searched — mirroring MetadataValidator's own rule.
     */
    public function testDoesNotUseAnyUuidDirectoryWhenMultipleCandidatesExist(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->listingResponse([
                $this->dir('one@example.com'),
                $this->dir('two@example.com'),
            ]),
        ]);

        self::assertNull($this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * The stored URL is pinned to a commit SHA, so an unchanged head commit
     * means the answer cannot have changed: no listing call, no image probe.
     */
    public function testUnchangedHeadCommitReusesTheStoredIconWithoutFurtherRequests(): void
    {
        $existing = $this->rawUrl('logo.svg');
        $httpClient = new MockHttpClient([$this->commitsResponse()]);

        self::assertSame($existing, $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing));
    }

    public function testNewHeadCommitTriggersAFreshResolution(): void
    {
        $newSha = 'ffffffffffffffffffffffffffffffffffffffff';
        $existing = $this->rawUrl('logo-old.png');

        $httpClient = new MockHttpClient([
            $this->commitsResponse($newSha),
            $this->listingResponse([$this->file('logo.png')]),
            ...$this->rasterResponses($this->pngBytes(), 'image/png'),
        ]);

        self::assertSame(
            'https://raw.githubusercontent.com/owner/repo/' . $newSha . '/logo.png',
            $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing),
        );
    }

    /**
     * A temporary API failure must not silently delete an icon that is
     * already known to be good.
     */
    public function testTransientRootListingFailureKeepsTheStoredIcon(): void
    {
        $existing = $this->rawUrl('logo.png');
        $httpClient = new MockHttpClient([
            $this->commitsResponse('ffffffffffffffffffffffffffffffffffffffff'),
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
        ]);

        self::assertSame($existing, $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing));
    }

    public function testUnavailableCommitLookupKeepsTheStoredIcon(): void
    {
        $existing = $this->rawUrl('logo.png');
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
        ]);

        self::assertSame($existing, $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing));
    }

    public function testRateLimitDuringCommitLookupAbortsInsteadOfSilentlyKeepingData(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]),
        ]);

        $this->expectException(ApiException::class);
        $this->resolver($httpClient)->resolve('token', 'owner', 'repo');
    }

    public function testRateLimitDuringRootListingAborts(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            new MockResponse('{"message":"API rate limit exceeded"}', ['http_code' => 429]),
        ]);

        $this->expectException(ApiException::class);
        $this->resolver($httpClient)->resolve('token', 'owner', 'repo');
    }

    public function testRepositoryWithoutAnAccessibleRootHasNoIcon(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->notFoundResponse(),
        ]);

        self::assertNull($this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * Regression test for SHA-shortcut robustness: an existing URL with an old
     * commit segment but a filename/path that contains the current SHA must NOT
     * be reused (would fail with old str_contains-based bug). The resolver should
     * detect the mismatch and perform a fresh resolution.
     *
     * Example: existing URL is
     *   https://raw.githubusercontent.com/owner/repo/ffffffffffffffffffffffffffffffffffffffff/a1b2c3d4e5f60718293a4b5c6d7e8f9012345678.png
     *
     * Current HEAD is a1b2c3d4e5f60718293a4b5c6d7e8f9012345678, and the fresh
     * listing returns logo.png. The old str_contains bug would keep the existing
     * URL because it contains self::SHA (in the filename), but the robust check
     * must reject it and return the fresh rawUrl('logo.png').
     */
     public function testRobustShaShortcutExistingUrlWithOldCommitButFilenameContainsCurrentSha(): void
    {
        $oldSha = 'ffffffffffffffffffffffffffffffffffffffff';
        $existing = 'https://raw.githubusercontent.com/owner/repo/' . $oldSha . '/' . self::SHA . '.png';

        $httpClient = new MockHttpClient([
            $this->commitsResponse(self::SHA),
            $this->listingResponse([$this->file('logo.png')]),
            ...$this->rasterResponses($this->pngBytes(), 'image/png'),
        ]);

        self::assertSame($this->rawUrl('logo.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing));
    }
}
