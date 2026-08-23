<?php

namespace App\Service\GitHub;

/**
 * Already-loaded facts about a candidate image referenced from a GitHub
 * source, as would be gathered by a later HTTP layer. This DTO carries no
 * behaviour and triggers no HTTP call, download, or byte-level MIME
 * sniffing itself.
 */
final class ImageCandidate
{
    /**
     * @param string   $requestedUrl      the originally referenced URL, before any redirect
     * @param string[] $redirectChain     URLs visited after the requested URL, in order; the
     *                                    last entry (if any) is expected to equal $finalUrl
     * @param string   $finalUrl          the URL the request ultimately resolved to
     * @param string   $contentType       the response content type, e.g. "image/png" or "image/svg+xml"
     * @param int      $contentLengthBytes the response content length in bytes
     * @param int|null $widthPx           pixel width, for raster images
     * @param int|null $heightPx          pixel height, for raster images
     * @param string|null $svgContent     the raw SVG document content, only for SVG candidates
     */
    public function __construct(
        public readonly string $requestedUrl,
        public readonly array $redirectChain,
        public readonly string $finalUrl,
        public readonly string $contentType,
        public readonly int $contentLengthBytes,
        public readonly ?int $widthPx = null,
        public readonly ?int $heightPx = null,
        public readonly ?string $svgContent = null,
    ) {
    }
}
