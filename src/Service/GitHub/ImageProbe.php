<?php

namespace App\Service\GitHub;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gathers the HTTP facts about a candidate image that
 * {@see ImageValidator} needs in order to decide anything:
 * content type, content length, the redirect chain, plus pixel dimensions
 * for raster images or the document body for SVGs.
 *
 * This is the missing half of the image pipeline: the validator is pure and
 * rejects a candidate whose dimensions are unknown, so without a probe no
 * image could ever be accepted.
 *
 * Two safety properties are deliberately enforced *here* rather than left to
 * the validator, because the validator only ever sees an already-completed
 * request:
 *
 * - Redirects are followed manually and every hop is checked against the
 *   allowlist *before* it is requested, so a redirect can never be used to
 *   make the server fetch an arbitrary address.
 * - Only a bounded prefix of the body is ever read, so an oversized or
 *   endless response cannot exhaust memory even when the server lies about
 *   (or omits) Content-Length.
 *
 * These requests go to the raw content hosts, not to api.github.com, so they
 * do not consume the GitHub API rate limit.
 */
final class ImageProbe
{
    private const USER_AGENT = 'ExtensionHub-GitHub-Extension-Indexer';

    /**
     * One more hop than the validator permits, so an over-long chain is
     * still reported as such (and rejected) instead of silently truncated
     * into a chain that looks acceptable.
     */
    private const MAX_REDIRECTS_FOLLOWED = 4;

    /**
     * Enough for the header of any PNG/JPEG/WebP: the dimensions live in the
     * first few bytes (PNG/WebP) or the first SOF marker (JPEG).
     */
    private const DIMENSION_PROBE_BYTES = 65536;

    /** Matches the validator's limit, so nothing larger is ever buffered. */
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;

    private const SVG_CONTENT_TYPE = 'image/svg+xml';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ImageValidator $validator,
    ) {
    }

    /**
     * Returns the gathered facts, or null when the URL could not be probed
     * at all (disallowed host, transport error, or HTTP error status). A
     * successfully probed but unsuitable image still yields a candidate, so
     * the validator — not this class — decides and names the rejection.
     */
    public function probe(string $url): ?ImageCandidate
    {
        if (!$this->validator->isRequestableUrl($url)) {
            return null;
        }

        $currentUrl = $url;
        $redirectChain = [];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS_FOLLOWED; $hop++) {
            $response = $this->request($currentUrl);
            if ($response === null) {
                return null;
            }

            [$statusCode, $headers] = $response;

            $location = $this->redirectTarget($statusCode, $headers);
            if ($location === null) {
                return $statusCode >= 400
                    ? null
                    : $this->buildCandidate($url, $redirectChain, $currentUrl, $headers);
            }

            $currentUrl = $this->absolutize($location, $currentUrl);
            $redirectChain[] = $currentUrl;

            // Report the disallowed hop instead of requesting it: the
            // validator turns this chain into a precise rejection reason.
            if (!$this->validator->isRequestableUrl($currentUrl)) {
                return $this->unfetchableCandidate($url, $redirectChain, $currentUrl);
            }
        }

        // Chain longer than we are willing to follow; hand it to the
        // validator, which rejects it as too_many_redirects.
        return $this->unfetchableCandidate($url, $redirectChain, $currentUrl);
    }

    /**
     * Header-only request used for redirect following and for content
     * type/length. All three allowlisted hosts answer HEAD, and it keeps the
     * server from starting a body transfer we would discard.
     *
     * @return array{0: int, 1: array<string, string[]>}|null
     */
    private function request(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('HEAD', $url, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'image/*',
                ],
                'max_redirects' => 0,
            ]);

            return [$response->getStatusCode(), $response->getHeaders(false)];
        } catch (HttpClientExceptionInterface) {
            return null;
        }
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function redirectTarget(int $statusCode, array $headers): ?string
    {
        if ($statusCode < 300 || $statusCode >= 400) {
            return null;
        }

        $location = $headers['location'][0] ?? null;

        return is_string($location) && $location !== '' ? $location : null;
    }

    /**
     * @param string[] $redirectChain
     */
    private function unfetchableCandidate(string $requestedUrl, array $redirectChain, string $finalUrl): ImageCandidate
    {
        return new ImageCandidate(
            requestedUrl: $requestedUrl,
            redirectChain: $redirectChain,
            finalUrl: $finalUrl,
            contentType: '',
            contentLengthBytes: 0,
        );
    }

    /**
     * @param string[]                $redirectChain
     * @param array<string, string[]> $headers
     */
    private function buildCandidate(string $requestedUrl, array $redirectChain, string $finalUrl, array $headers): ImageCandidate
    {
        $contentType = $this->normalizeContentType($headers['content-type'][0] ?? '');
        $declaredLength = (int) ($headers['content-length'][0] ?? 0);

        if ($contentType === self::SVG_CONTENT_TYPE) {
            $body = $this->readBody($finalUrl, self::MAX_BODY_BYTES);

            return new ImageCandidate(
                requestedUrl: $requestedUrl,
                redirectChain: $redirectChain,
                finalUrl: $finalUrl,
                contentType: $contentType,
                contentLengthBytes: $declaredLength > 0 ? $declaredLength : strlen($body ?? ''),
                svgContent: $body,
            );
        }

        [$widthPx, $heightPx] = $this->readDimensions($finalUrl, $declaredLength);

        return new ImageCandidate(
            requestedUrl: $requestedUrl,
            redirectChain: $redirectChain,
            finalUrl: $finalUrl,
            contentType: $contentType,
            contentLengthBytes: $declaredLength,
            widthPx: $widthPx,
            heightPx: $heightPx,
        );
    }

    /**
     * Dimensions are only read for a response that already claims a
     * plausible size, so an oversized image is rejected on its declared
     * length without downloading any of it.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function readDimensions(string $url, int $declaredLength): array
    {
        if ($declaredLength > self::MAX_BODY_BYTES) {
            return [null, null];
        }

        $body = $this->readBody($url, self::DIMENSION_PROBE_BYTES);
        if ($body === null || $body === '') {
            return [null, null];
        }

        $size = @getimagesizefromstring($body);
        if (!is_array($size) || !isset($size[0], $size[1])) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * Streams at most $maxBytes of the body. The caller has already had the
     * final URL vetted against the allowlist.
     */
    private function readBody(string $url, int $maxBytes): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'image/*',
                ],
                'max_redirects' => 0,
            ]);

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $body = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) >= $maxBytes) {
                    break;
                }
            }

            return $body;
        } catch (HttpClientExceptionInterface) {
            return null;
        }
    }

    private function normalizeContentType(string $headerValue): string
    {
        return strtolower(trim(explode(';', $headerValue, 2)[0]));
    }

    /**
     * Resolves a Location header against the URL it was returned for,
     * covering absolute, protocol-relative, root-relative and path-relative
     * targets.
     */
    private function absolutize(string $location, string $baseUrl): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['host'])) {
            return $location;
        }

        $origin = ($base['scheme'] ?? 'https') . '://' . $base['host'];

        if (str_starts_with($location, '//')) {
            return ($base['scheme'] ?? 'https') . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $basePath = $base['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return $origin . $directory . $location;
    }
}
