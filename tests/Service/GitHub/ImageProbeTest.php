<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\ImageProbe;
use App\Service\GitHub\ImageValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * HTTP fact gathering for candidate images, tested exclusively against a
 * MockHttpClient: no live request is made.
 */
class ImageProbeTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private function rawUrl(string $path = 'assets/demo2.png'): string
    {
        return 'https://raw.githubusercontent.com/owner/repo/' . self::SHA . '/' . $path;
    }

    private function probe(MockHttpClient $httpClient): ImageProbe
    {
        return new ImageProbe($httpClient, new ImageValidator());
    }

    /**
     * Minimal but genuine PNG bytes, so getimagesizefromstring() reports real
     * dimensions instead of the test asserting on a stub.
     */
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function headResponse(string $contentType, int $contentLength): MockResponse
    {
        return new MockResponse('', ['response_headers' => [
            'content-type' => $contentType,
            'content-length' => (string) $contentLength,
        ]]);
    }

    private function redirectResponse(string $location, int $statusCode = 302): MockResponse
    {
        return new MockResponse('', [
            'http_code' => $statusCode,
            'response_headers' => ['location' => $location],
        ]);
    }

    public function testGathersContentTypeLengthAndRealPixelDimensions(): void
    {
        $png = $this->pngBytes(800, 450);
        $httpClient = new MockHttpClient([
            $this->headResponse('image/png', strlen($png)),
            new MockResponse($png, ['response_headers' => ['content-type' => 'image/png']]),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertNotNull($candidate);
        self::assertSame('image/png', $candidate->contentType);
        self::assertSame(strlen($png), $candidate->contentLengthBytes);
        self::assertSame(800, $candidate->widthPx);
        self::assertSame(450, $candidate->heightPx);
        self::assertSame([], $candidate->redirectChain);
        self::assertSame($this->rawUrl(), $candidate->finalUrl);
    }

    public function testProbedRasterImagePassesValidation(): void
    {
        $png = $this->pngBytes(800, 450);
        $httpClient = new MockHttpClient([
            $this->headResponse('image/png', strlen($png)),
            new MockResponse($png),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertTrue((new ImageValidator())->validate($candidate)->valid);
    }

    public function testStripsContentTypeParametersAndLowercasesIt(): void
    {
        $png = $this->pngBytes(400, 300);
        $httpClient = new MockHttpClient([
            $this->headResponse('Image/PNG; charset=binary', strlen($png)),
            new MockResponse($png),
        ]);

        self::assertSame('image/png', $this->probe($httpClient)->probe($this->rawUrl())->contentType);
    }

    /**
     * The allowlist has to gate the outgoing request, not just the validation
     * of a completed one — otherwise the probe itself becomes an SSRF tool.
     */
    public function testRefusesToRequestADisallowedHostAtAll(): void
    {
        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('No request may be sent to a disallowed host.');
        });

        self::assertNull($this->probe($httpClient)->probe('https://evil.example.org/shot.png'));
    }

    public function testRefusesPlainHttpUrls(): void
    {
        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('No request may be sent over plain HTTP.');
        });

        self::assertNull($this->probe($httpClient)->probe('http://raw.githubusercontent.com/owner/repo/main/a.png'));
    }

    /**
     * A redirect leaving the allowlist must be recorded and reported, never
     * requested — and the validator then names the exact reason.
     */
    public function testDoesNotFollowARedirectLeavingTheAllowlistButReportsIt(): void
    {
        $httpClient = new MockHttpClient([
            $this->redirectResponse('https://internal.example.org/secret'),
            new MockResponse('', ['http_code' => 500]),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertNotNull($candidate);
        self::assertSame(['https://internal.example.org/secret'], $candidate->redirectChain);
        self::assertSame('https://internal.example.org/secret', $candidate->finalUrl);

        $result = (new ImageValidator())->validate($candidate);
        self::assertFalse($result->valid);
        self::assertSame('redirect_target_host_not_allowed', $result->reason);
    }

    public function testFollowsAllowedRedirectsAndRecordsTheChain(): void
    {
        $png = $this->pngBytes(600, 400);
        $httpClient = new MockHttpClient([
            $this->redirectResponse('https://user-images.githubusercontent.com/1/hop.png'),
            $this->headResponse('image/png', strlen($png)),
            new MockResponse($png),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertSame(['https://user-images.githubusercontent.com/1/hop.png'], $candidate->redirectChain);
        self::assertSame('https://user-images.githubusercontent.com/1/hop.png', $candidate->finalUrl);
        self::assertSame(600, $candidate->widthPx);
    }

    public function testResolvesRelativeRedirectLocationAgainstTheCurrentUrl(): void
    {
        $png = $this->pngBytes(500, 500);
        $httpClient = new MockHttpClient([
            $this->redirectResponse('/owner/repo/main/moved.png'),
            $this->headResponse('image/png', strlen($png)),
            new MockResponse($png),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertSame('https://raw.githubusercontent.com/owner/repo/main/moved.png', $candidate->finalUrl);
    }

    public function testTooLongRedirectChainIsReportedAndRejected(): void
    {
        $hop = 'https://raw.githubusercontent.com/owner/repo/main/hop.png';
        $httpClient = new MockHttpClient(array_fill(0, 6, $this->redirectResponse($hop)));

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertNotNull($candidate);
        $result = (new ImageValidator())->validate($candidate);
        self::assertFalse($result->valid);
        self::assertSame('too_many_redirects', $result->reason);
    }

    public function testHttpErrorStatusYieldsNoCandidate(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('', ['http_code' => 404])]);

        self::assertNull($this->probe($httpClient)->probe($this->rawUrl()));
    }

    /**
     * An image whose declared size already exceeds the limit is rejected on
     * its headers; no body is fetched for it.
     */
    public function testOversizedImageIsNotDownloadedForDimensions(): void
    {
        $httpClient = new MockHttpClient([
            $this->headResponse('image/png', 20 * 1024 * 1024),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertNotNull($candidate);
        self::assertNull($candidate->widthPx);
        self::assertSame(20 * 1024 * 1024, $candidate->contentLengthBytes);
        self::assertSame('content_too_large', (new ImageValidator())->validate($candidate)->reason);
    }

    public function testUndecodableBodyLeavesDimensionsUnknown(): void
    {
        $httpClient = new MockHttpClient([
            $this->headResponse('image/png', 1234),
            new MockResponse('this is not an image'),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl());

        self::assertNull($candidate->widthPx);
        self::assertSame('dimensions_missing', (new ImageValidator())->validate($candidate)->reason);
    }

    public function testSvgCandidateCarriesItsDocumentBody(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
        $httpClient = new MockHttpClient([
            $this->headResponse('image/svg+xml', strlen($svg)),
            new MockResponse($svg),
        ]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl('assets/logo.svg'));

        self::assertSame('image/svg+xml', $candidate->contentType);
        self::assertSame($svg, $candidate->svgContent);
        self::assertNull($candidate->widthPx);
        self::assertTrue((new ImageValidator())->validate($candidate)->valid);
    }

    public function testUnsupportedContentTypeStillYieldsACandidateForTheValidatorToReject(): void
    {
        $httpClient = new MockHttpClient([$this->headResponse('text/html', 500)]);

        $candidate = $this->probe($httpClient)->probe($this->rawUrl('index.html'));

        self::assertSame('text/html', $candidate->contentType);
        self::assertSame('unsupported_content_type', (new ImageValidator())->validate($candidate)->reason);
    }
}
