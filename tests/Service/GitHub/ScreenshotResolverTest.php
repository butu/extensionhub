<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\ApiCache;
use App\Service\GitHub\ApiClient;
use App\Service\GitHub\ApiException;
use App\Service\GitHub\ImageProbe;
use App\Service\GitHub\ImageValidator;
use App\Service\GitHub\ReadmeImageExtractor;
use App\Service\GitHub\ScreenshotResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Screenshot selection end to end, tested exclusively against a
 * MockHttpClient: no live GitHub or raw-content request is made.
 */
class ScreenshotResolverTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-screenshot-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function resolver(MockHttpClient $httpClient): ScreenshotResolver
    {
        $apiClient = new ApiClient($httpClient, new ApiCache(new ParameterBag(['kernel.project_dir' => $this->cacheDir])));
        $validator = new ImageValidator();

        return new ScreenshotResolver(
            $apiClient,
            new ReadmeImageExtractor(),
            new ImageProbe($httpClient, $validator),
            $validator,
        );
    }

    private function rawUrl(string $path): string
    {
        return 'https://raw.githubusercontent.com/owner/repo/' . self::SHA . '/' . $path;
    }

    private function commitsResponse(string $sha = self::SHA): JsonMockResponse
    {
        return new JsonMockResponse([['sha' => $sha]]);
    }

    private function readmeResponse(string $markdown, string $path = 'README.md'): JsonMockResponse
    {
        return new JsonMockResponse([
            'name' => basename($path),
            'path' => $path,
            'type' => 'file',
            'encoding' => 'base64',
            'content' => base64_encode($markdown),
        ]);
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * The HEAD + GET pair the probe performs for one raster candidate.
     *
     * @return MockResponse[]
     */
    private function imageResponses(int $width, int $height, string $contentType = 'image/png'): array
    {
        $png = $this->pngBytes($width, $height);

        return [
            new MockResponse('', ['response_headers' => [
                'content-type' => $contentType,
                'content-length' => (string) strlen($png),
            ]]),
            new MockResponse($png),
        ];
    }

    private function notFoundResponse(): MockResponse
    {
        return new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);
    }

    public function testPicksTheFirstValidReadmeImage(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->readmeResponse("![Dash to Dock](assets/demo2.png)\n![Notifications](assets/demo3.png)"),
            ...$this->imageResponses(800, 450),
        ]);

        self::assertSame($this->rawUrl('assets/demo2.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * Shields.io badges are the most common first images in a README; the
     * host allowlist rejects them without any request being sent, so the
     * first real screenshot wins.
     */
    public function testSkipsBadgesOnDisallowedHostsAndPicksTheRealScreenshot(): void
    {
        $markdown = <<<'MD'
            ![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
            ![GNOME Shell](https://img.shields.io/badge/GNOME-50-green.svg)
            ![Dash to Dock Screenshot](assets/demo2.png)
            MD;

        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->readmeResponse($markdown),
            ...$this->imageResponses(1280, 720),
        ]);

        self::assertSame($this->rawUrl('assets/demo2.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * A small inline icon that happens to appear first must not become the
     * screenshot just because it is technically a valid image.
     */
    public function testSkipsImagesTooSmallToBeAScreenshot(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->readmeResponse("![icon](assets/icon.png)\n![shot](assets/shot.png)"),
            ...$this->imageResponses(48, 48),
            ...$this->imageResponses(1024, 640),
        ]);

        self::assertSame($this->rawUrl('assets/shot.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    public function testReturnsNullWhenNoReadmeImagePassesThePolicy(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->readmeResponse('![tiny](assets/tiny.png)'),
            ...$this->imageResponses(16, 16),
        ]);

        self::assertNull($this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    public function testRepositoryWithoutReadmeHasNoScreenshot(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->notFoundResponse(),
        ]);

        self::assertNull($this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    public function testReadmeWithoutAnyImageHasNoScreenshot(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->readmeResponse('# Just prose'),
        ]);

        self::assertNull($this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * The stored URL is pinned to a commit SHA, so an unchanged head commit
     * means the answer cannot have changed: no README read, no image probe.
     */
    public function testUnchangedHeadCommitReusesTheStoredScreenshotWithoutFurtherRequests(): void
    {
        $existing = $this->rawUrl('assets/demo2.png');
        $httpClient = new MockHttpClient([$this->commitsResponse()]);

        self::assertSame($existing, $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing));
    }

    public function testNewHeadCommitTriggersAFreshResolution(): void
    {
        $newSha = 'ffffffffffffffffffffffffffffffffffffffff';
        $existing = $this->rawUrl('assets/old.png');

        $httpClient = new MockHttpClient([
            $this->commitsResponse($newSha),
            $this->readmeResponse('![new](assets/new.png)'),
            ...$this->imageResponses(900, 500),
        ]);

        self::assertSame(
            'https://raw.githubusercontent.com/owner/repo/' . $newSha . '/assets/new.png',
            $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing),
        );
    }

    /**
     * A temporary API failure must not silently delete a screenshot that is
     * already known to be good.
     */
    public function testTransientReadmeFailureKeepsTheStoredScreenshot(): void
    {
        $existing = $this->rawUrl('assets/demo2.png');
        $httpClient = new MockHttpClient([
            $this->commitsResponse('ffffffffffffffffffffffffffffffffffffffff'),
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
        ]);

        self::assertSame($existing, $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing));
    }

    public function testUnavailableCommitLookupKeepsTheStoredScreenshot(): void
    {
        $existing = $this->rawUrl('assets/demo2.png');
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

    public function testRateLimitDuringReadmeLoadAborts(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 429,
            ]),
        ]);

        $this->expectException(ApiException::class);
        $this->resolver($httpClient)->resolve('token', 'owner', 'repo');
    }

    /**
     * A README full of images must not turn one repository into an unbounded
     * number of HTTP requests. Only the first 8 candidates are probed, so a
     * valid image at position 9 is never reached.
     */
    public function testStopsProbingAfterTheCandidateLimit(): void
    {
        $markdown = '';
        for ($i = 1; $i <= 8; $i++) {
            $markdown .= sprintf("![tiny %d](assets/tiny%d.png)\n", $i, $i);
        }
        $markdown .= '![real](assets/real.png)';

        $responses = [$this->commitsResponse(), $this->readmeResponse($markdown)];
        for ($i = 1; $i <= 8; $i++) {
            $responses = array_merge($responses, $this->imageResponses(10, 10));
        }
        // Intentionally no responses for assets/real.png: reaching it would
        // make MockHttpClient fail with "no more responses left".
        $httpClient = new MockHttpClient($responses);

        self::assertNull($this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    public function testResolvesReadmeOutsideTheRepositoryRootRelativeToItsDirectory(): void
    {
        $httpClient = new MockHttpClient([
            $this->commitsResponse(),
            $this->readmeResponse('![shot](img/shot.png)', 'docs/README.md'),
            ...$this->imageResponses(800, 600),
        ]);

        self::assertSame($this->rawUrl('docs/img/shot.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo'));
    }

    /**
     * Regression test for SHA-shortcut robustness: an existing URL with an old
     * commit segment but a path/filename that contains the current SHA must NOT
     * be reused (would fail with old str_contains-based bug). The resolver should
     * detect the mismatch and perform a fresh resolution.
     *
     * Example: existing URL is
     *   https://raw.githubusercontent.com/owner/repo/ffffffffffffffffffffffffffffffffffffffff/assets/a1b2c3d4e5f60718293a4b5c6d7e8f9012345678/shot.png
     *
     * Current HEAD is a1b2c3d4e5f60718293a4b5c6d7e8f9012345678, the README contains
     * ![new](assets/new.png), and the image validates. The old str_contains bug would
     * keep the existing URL because it contains self::SHA (in the path), but the
     * robust check must reject it and return the fresh rawUrl('assets/new.png').
     */
     public function testRobustShaShortcutExistingUrlWithOldCommitButPathContainsCurrentSha(): void
    {
        $oldSha = 'ffffffffffffffffffffffffffffffffffffffff';
        $existing = 'https://raw.githubusercontent.com/owner/repo/' . $oldSha . '/assets/' . self::SHA . '/shot.png';

        $httpClient = new MockHttpClient([
            $this->commitsResponse(self::SHA),
            $this->readmeResponse('![new](assets/new.png)'),
            ...$this->imageResponses(900, 500),
        ]);

        self::assertSame($this->rawUrl('assets/new.png'), $this->resolver($httpClient)->resolve('token', 'owner', 'repo', $existing));
    }
}
