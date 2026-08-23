<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\ImageCandidate;
use App\Service\GitHub\ImageValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pure image/SVG policy validation against already-loaded facts, deliberately
 * tested without any HTTP call, download, or byte-level MIME sniffing.
 */
class ImageValidatorTest extends TestCase
{
    private const VALID_COMMIT_SHA = '0123456789abcdef0123456789abcdef01234567';

    private function rasterCandidate(
        string $url = 'https://raw.githubusercontent.com/owner/repo/main/icon.png',
        array $redirectChain = [],
        ?string $finalUrl = null,
        string $contentType = 'image/png',
        int $contentLengthBytes = 1024,
        ?int $widthPx = 64,
        ?int $heightPx = 64,
    ): ImageCandidate {
        return new ImageCandidate(
            requestedUrl: $url,
            redirectChain: $redirectChain,
            finalUrl: $finalUrl ?? ($redirectChain !== [] ? $redirectChain[array_key_last($redirectChain)] : $url),
            contentType: $contentType,
            contentLengthBytes: $contentLengthBytes,
            widthPx: $widthPx,
            heightPx: $heightPx,
        );
    }

    private function svgCandidate(
        string $url,
        string $svgContent,
        array $redirectChain = [],
        ?string $finalUrl = null,
    ): ImageCandidate {
        return new ImageCandidate(
            requestedUrl: $url,
            redirectChain: $redirectChain,
            finalUrl: $finalUrl ?? ($redirectChain !== [] ? $redirectChain[array_key_last($redirectChain)] : $url),
            contentType: 'image/svg+xml',
            contentLengthBytes: strlen($svgContent),
            svgContent: $svgContent,
        );
    }

    private function safeSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="#fff"/></svg>';
    }

    private function commitShaUrl(string $path = 'icon.svg'): string
    {
        return 'https://raw.githubusercontent.com/owner/repo/' . self::VALID_COMMIT_SHA . '/' . $path;
    }

    // --- valid raster formats ---

    public function testValidPngIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(contentType: 'image/png'));

        self::assertTrue($result->valid);
        self::assertNull($result->reason);
    }

    public function testValidJpegIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(contentType: 'image/jpeg'));

        self::assertTrue($result->valid);
    }

    public function testValidWebpIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(contentType: 'image/webp'));

        self::assertTrue($result->valid);
    }

    /**
     * GIF is accepted as a raster format: repositories commonly ship an
     * animated or simply GIF-encoded icon/logo, and the validator has no
     * reason to treat it differently from PNG/JPEG/WebP.
     */
    public function testValidGifIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(contentType: 'image/gif'));

        self::assertTrue($result->valid);
    }

    // --- host / scheme ---

    public function testHttpInsteadOfHttpsIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(
            url: 'http://raw.githubusercontent.com/owner/repo/main/icon.png',
        ));

        self::assertFalse($result->valid);
        self::assertSame('url_not_https', $result->reason);
    }

    public function testWrongHostIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(
            url: 'https://evil.example.com/icon.png',
        ));

        self::assertFalse($result->valid);
        self::assertSame('url_host_not_allowed', $result->reason);
    }

    // --- redirects ---

    public function testMoreThanThreeRedirectsIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(
            redirectChain: [
                'https://raw.githubusercontent.com/owner/repo/main/1.png',
                'https://raw.githubusercontent.com/owner/repo/main/2.png',
                'https://raw.githubusercontent.com/owner/repo/main/3.png',
                'https://raw.githubusercontent.com/owner/repo/main/4.png',
            ],
        ));

        self::assertFalse($result->valid);
        self::assertSame('too_many_redirects', $result->reason);
    }

    public function testExactlyThreeRedirectsIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(
            redirectChain: [
                'https://raw.githubusercontent.com/owner/repo/main/1.png',
                'https://raw.githubusercontent.com/owner/repo/main/2.png',
                'https://raw.githubusercontent.com/owner/repo/main/3.png',
            ],
        ));

        self::assertTrue($result->valid);
    }

    public function testRedirectToDisallowedHostIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(
            redirectChain: [
                'https://raw.githubusercontent.com/owner/repo/main/1.png',
                'https://evil.example.com/icon.png',
            ],
        ));

        self::assertFalse($result->valid);
        self::assertSame('redirect_target_host_not_allowed', $result->reason);
    }

    public function testFinalUrlOnDisallowedHostIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(
            finalUrl: 'https://evil.example.com/icon.png',
        ));

        self::assertFalse($result->valid);
        self::assertSame('final_url_host_not_allowed', $result->reason);
    }

    // --- content type / size / dimensions ---

    public function testUnsupportedContentTypeIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(contentType: 'image/bmp'));

        self::assertFalse($result->valid);
        self::assertSame('unsupported_content_type', $result->reason);
    }

    public function testContentLargerThanFiveMebibytesIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(contentLengthBytes: 5 * 1024 * 1024 + 1));

        self::assertFalse($result->valid);
        self::assertSame('content_too_large', $result->reason);
    }

    public function testContentAtExactlyFiveMebibytesIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(contentLengthBytes: 5 * 1024 * 1024));

        self::assertTrue($result->valid);
    }

    public function testDimensionAboveFourThousandNinetySixIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(widthPx: 4097, heightPx: 100));

        self::assertFalse($result->valid);
        self::assertSame('dimension_too_large', $result->reason);
    }

    public function testHeightAboveFourThousandNinetySixIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(widthPx: 100, heightPx: 4097));

        self::assertFalse($result->valid);
        self::assertSame('dimension_too_large', $result->reason);
    }

    public function testDimensionAtExactlyFourThousandNinetySixIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(widthPx: 4096, heightPx: 4096));

        self::assertTrue($result->valid);
    }

    public function testMissingDimensionsIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->rasterCandidate(widthPx: null, heightPx: null));

        self::assertFalse($result->valid);
        self::assertSame('dimensions_missing', $result->reason);
    }

    // --- SVG ---

    public function testValidSvgWithCommitShaUrlIsAccepted(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $this->safeSvg()));

        self::assertTrue($result->valid);
        self::assertNull($result->reason);
    }

    public function testSvgOnBranchUrlIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->svgCandidate(
            'https://raw.githubusercontent.com/owner/repo/main/icon.svg',
            $this->safeSvg(),
        ));

        self::assertFalse($result->valid);
        self::assertSame('svg_url_not_immutable', $result->reason);
    }

    public function testSvgOnNonRawHostIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->svgCandidate(
            'https://github.com/owner/repo/blob/' . self::VALID_COMMIT_SHA . '/icon.svg',
            $this->safeSvg(),
        ));

        self::assertFalse($result->valid);
        self::assertSame('svg_url_not_immutable', $result->reason);
    }

    public function testSvgWithScriptTagIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_script', $result->reason);
    }

    public function testSvgWithEventAttributeIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_event_attribute', $result->reason);
    }

    public function testSvgWithForeignObjectIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_foreign_object', $result->reason);
    }

    public function testSvgWithExternalHrefIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example.com/tracker.png"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_external_reference', $result->reason);
    }

    public function testSvgWithExternalXlinkHrefIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<use xlink:href="https://evil.example.com/sprite.svg#icon"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_external_reference', $result->reason);
    }

    public function testSvgWithInternalFragmentHrefIsAccepted(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<defs><path id="p" d="M0 0h1v1H0z"/></defs><use xlink:href="#p"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertTrue($result->valid);
    }

    public function testSvgWithExternalCssImportIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.example.com/x.css);</style></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_external_reference', $result->reason);
    }

    public function testSvgWithExternalCssUrlReferenceIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a { fill: url(https://evil.example.com/x.png); }</style></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_external_reference', $result->reason);
    }

    public function testMissingSvgContentIsRejected(): void
    {
        $validator = new ImageValidator();

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), ''));

        self::assertFalse($result->valid);
        self::assertSame('svg_content_missing', $result->reason);
    }

    // --- regression tests for reported regex-bypasses (must be caught by DOM-based parsing) ---

    public function testSvgWithUnquotedEventAttributeIsRejectedAsInvalidXml(): void
    {
        $validator = new ImageValidator();

        // Unquoted attribute values are not well-formed XML; a regex looking
        // only for on...=" or on...=' would miss this entirely.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload=alert(1)><rect width="1" height="1"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_invalid_xml', $result->reason);
    }

    public function testSvgWithJavascriptHrefUriIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)">'
            . '<rect width="1" height="1"/></a></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_unsafe_uri_scheme', $result->reason);
    }

    public function testSvgWithJavascriptXlinkHrefUriIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<use xlink:href="javascript:alert(1)"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_unsafe_uri_scheme', $result->reason);
    }

    public function testSvgWithDataUriHrefIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/svg+xml;base64,PHN2Zz4="/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_unsafe_uri_scheme', $result->reason);
    }

    public function testSvgWithDoctypeIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<?xml version="1.0"?>'
            . '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg">&xxe;</svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_doctype', $result->reason);
    }

    public function testSvgWithLowercaseDoctypeIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<!doctype svg><svg xmlns="http://www.w3.org/2000/svg"></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_doctype', $result->reason);
    }

    public function testSvgWithNonSvgRootElementIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<html xmlns="http://www.w3.org/1999/xhtml"><body>hi</body></html>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_root_element_invalid', $result->reason);
    }

    public function testSvgWithMalformedXmlIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_invalid_xml', $result->reason);
    }

    // --- regression tests for reported presentation-attribute url() bypasses ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function presentationAttributesWithUrlFunctionProvider(): iterable
    {
        yield 'fill' => ['fill'];
        yield 'mask' => ['mask'];
        yield 'filter' => ['filter'];
        yield 'clip-path' => ['clip-path'];
        yield 'cursor' => ['cursor'];
    }

    /**
     * @dataProvider presentationAttributesWithUrlFunctionProvider
     */
    public function testSvgWithExternalUrlFunctionInPresentationAttributeIsRejected(string $attributeName): void
    {
        $validator = new ImageValidator();

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" %s="url(https://evil.example.com/x.png)"/></svg>',
            $attributeName,
        );

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_external_reference', $result->reason);
    }

    public function testSvgWithDataUrlFunctionInFillAttributeIsRejected(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<rect width="1" height="1" fill="url(data:image/png;base64,AAAA)"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertFalse($result->valid);
        self::assertSame('svg_contains_unsafe_uri_scheme', $result->reason);
    }

    public function testSvgWithInternalFragmentUrlFunctionInFillAttributeIsAccepted(): void
    {
        $validator = new ImageValidator();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"/></defs>'
            . '<rect width="1" height="1" fill="url(#g)"/></svg>';

        $result = $validator->validate($this->svgCandidate($this->commitShaUrl(), $svg));

        self::assertTrue($result->valid);
    }

    /**
     * @dataProvider requestableUrlProvider
     */
    public function testIsRequestableUrlGatesOutgoingRequestsByHostAndScheme(string $url, bool $expected): void
    {
        self::assertSame($expected, (new ImageValidator())->isRequestableUrl($url));
    }

    public static function requestableUrlProvider(): array
    {
        return [
            'raw host over https' => ['https://raw.githubusercontent.com/owner/repo/main/a.png', true],
            'github.com over https' => ['https://github.com/owner/repo/raw/main/a.png', true],
            'user images host' => ['https://user-images.githubusercontent.com/1/a.png', true],
            'uppercase host' => ['https://RAW.githubusercontent.COM/owner/repo/main/a.png', true],
            'plain http' => ['http://raw.githubusercontent.com/owner/repo/main/a.png', false],
            'foreign host' => ['https://img.shields.io/badge/License-MIT-blue.svg', false],
            'lookalike subdomain' => ['https://raw.githubusercontent.com.evil.example.org/a.png', false],
            'localhost' => ['https://127.0.0.1/a.png', false],
            'file scheme' => ['file:///etc/passwd', false],
            'not a url' => ['not a url at all', false],
        ];
    }
}
