<?php

namespace App\Service\GitHub;

/**
 * Pure validation of already-loaded facts about a candidate image
 * referenced from a GitHub source, free of any HTTP call, download, or
 * byte-level MIME sniffing. A later HTTP layer is expected to gather the
 * facts carried by {@see ImageCandidate}; this class only decides
 * whether they satisfy the allowed-image policy.
 *
 * See the individual {@see ImageValidationResult::invalid()} call
 * sites below for the exhaustive, self-documenting list of reason codes.
 */
final class ImageValidator
{
    private const ALLOWED_HOSTS = [
        'github.com',
        'raw.githubusercontent.com',
        'user-images.githubusercontent.com',
    ];

    private const MAX_REDIRECTS = 3;

    private const ALLOWED_RASTER_CONTENT_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    private const MAX_CONTENT_LENGTH_BYTES = 5 * 1024 * 1024;

    private const MAX_DIMENSION_PX = 4096;

    private const SVG_CONTENT_TYPE = 'image/svg+xml';

    /**
     * Whether a URL may be requested at all: HTTPS on an allowed host.
     *
     * Exposed so an HTTP layer ({@see ImageProbe}) can refuse to even
     * send a request to a disallowed host — the allowlist must gate outgoing
     * requests, not only the validation of an already-fetched response, or a
     * redirect could be used to reach an arbitrary internal address.
     */
    public function isRequestableUrl(string $url): bool
    {
        return $this->validateAllowedUrl($url, 'url_not_https', 'url_host_not_allowed') === null;
    }

    public function validate(ImageCandidate $candidate): ImageValidationResult
    {
        $urlResult = $this->validateRequestedUrl($candidate->requestedUrl);
        if ($urlResult !== null) {
            return $urlResult;
        }

        $redirectResult = $this->validateRedirectChain($candidate->redirectChain);
        if ($redirectResult !== null) {
            return $redirectResult;
        }

        $finalUrlResult = $this->validateFinalUrl($candidate->finalUrl);
        if ($finalUrlResult !== null) {
            return $finalUrlResult;
        }

        if (in_array($candidate->contentType, self::ALLOWED_RASTER_CONTENT_TYPES, true)) {
            return $this->validateRaster($candidate);
        }

        if ($candidate->contentType === self::SVG_CONTENT_TYPE) {
            return $this->validateSvg($candidate);
        }

        return ImageValidationResult::invalid('unsupported_content_type');
    }

    private function validateRequestedUrl(string $url): ?ImageValidationResult
    {
        return $this->validateAllowedUrl($url, 'url_not_https', 'url_host_not_allowed');
    }

    /**
     * @param string[] $redirectChain
     */
    private function validateRedirectChain(array $redirectChain): ?ImageValidationResult
    {
        if (count($redirectChain) > self::MAX_REDIRECTS) {
            return ImageValidationResult::invalid('too_many_redirects');
        }

        foreach ($redirectChain as $hopUrl) {
            $hopResult = $this->validateAllowedUrl($hopUrl, 'redirect_target_not_https', 'redirect_target_host_not_allowed');
            if ($hopResult !== null) {
                return $hopResult;
            }
        }

        return null;
    }

    private function validateFinalUrl(string $finalUrl): ?ImageValidationResult
    {
        return $this->validateAllowedUrl($finalUrl, 'final_url_not_https', 'final_url_host_not_allowed');
    }

    private function validateAllowedUrl(string $url, string $notHttpsReason, string $hostNotAllowedReason): ?ImageValidationResult
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return ImageValidationResult::invalid($notHttpsReason);
        }

        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            return ImageValidationResult::invalid($hostNotAllowedReason);
        }

        return null;
    }

    private function validateRaster(ImageCandidate $candidate): ImageValidationResult
    {
        if ($candidate->contentLengthBytes > self::MAX_CONTENT_LENGTH_BYTES) {
            return ImageValidationResult::invalid('content_too_large');
        }

        if ($candidate->widthPx === null || $candidate->heightPx === null) {
            return ImageValidationResult::invalid('dimensions_missing');
        }

        if ($candidate->widthPx > self::MAX_DIMENSION_PX || $candidate->heightPx > self::MAX_DIMENSION_PX) {
            return ImageValidationResult::invalid('dimension_too_large');
        }

        return ImageValidationResult::valid();
    }

    /**
     * SVG element local names that carry active-content risk on their own
     * and are therefore never allowed, regardless of attributes.
     */
    private const DISALLOWED_ELEMENT_NAMES = ['iframe', 'embed', 'object'];

    /**
     * Attribute local names (namespace prefix ignored) that may carry a
     * URI reference and are therefore checked against the same-document/
     * external-reference policy.
     */
    private const URI_ATTRIBUTE_NAMES = ['href', 'src'];

    private function validateSvg(ImageCandidate $candidate): ImageValidationResult
    {
        if ($candidate->svgContent === null || $candidate->svgContent === '') {
            return ImageValidationResult::invalid('svg_content_missing');
        }

        if (!$this->isImmutableCommitShaSvgUrl($candidate->finalUrl)) {
            return ImageValidationResult::invalid('svg_url_not_immutable');
        }

        if (!class_exists(\DOMDocument::class)) {
            return ImageValidationResult::invalid('svg_dom_extension_unavailable');
        }

        $content = $candidate->svgContent;

        // A DOCTYPE is the only way to declare (and expand) XML entities, so
        // banning it outright rules out entity-expansion and XXE payloads
        // without relying on parser-specific entity-loading defaults.
        if (stripos($content, '<!doctype') !== false) {
            return ImageValidationResult::invalid('svg_contains_doctype');
        }

        $document = $this->parseWellFormedXml($content);
        if ($document === null) {
            return ImageValidationResult::invalid('svg_invalid_xml');
        }

        $root = $document->documentElement;
        if ($root === null || strtolower($root->localName ?? $root->nodeName) !== 'svg') {
            return ImageValidationResult::invalid('svg_root_element_invalid');
        }

        return $this->scanSvgDocument($document) ?? ImageValidationResult::valid();
    }

    private function isImmutableCommitShaSvgUrl(string $finalUrl): bool
    {
        $parts = parse_url($finalUrl);
        if (!is_array($parts) || strtolower($parts['host'] ?? '') !== 'raw.githubusercontent.com') {
            return false;
        }

        $path = $parts['path'] ?? '';

        // /{owner}/{repo}/{40-char commit sha}/{path...}.svg
        return (bool) preg_match('#^/[^/]+/[^/]+/[0-9a-f]{40}/.+\.svg$#i', $path);
    }

    /**
     * Parses strictly well-formed XML with network access and DTD loading
     * disabled. Unquoted attributes, unclosed tags, and other non-well-formed
     * markup (which browsers may still render leniently in HTML contexts)
     * are rejected here rather than sniffed for individually, since an SVG
     * served as image/svg+xml is parsed as XML by browsers too.
     */
    private function parseWellFormedXml(string $content): ?\DOMDocument
    {
        $document = new \DOMDocument();

        $previousErrorSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $document->loadXML($content, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }

        return $loaded ? $document : null;
    }

    private function scanSvgDocument(\DOMDocument $document): ?ImageValidationResult
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            $elementResult = $this->scanElement($element);
            if ($elementResult !== null) {
                return $elementResult;
            }
        }

        return null;
    }

    private function scanElement(\DOMElement $element): ?ImageValidationResult
    {
        $localName = strtolower($element->localName ?? $element->nodeName);

        if ($localName === 'script') {
            return ImageValidationResult::invalid('svg_contains_script');
        }

        if ($localName === 'foreignobject') {
            return ImageValidationResult::invalid('svg_contains_foreign_object');
        }

        if (in_array($localName, self::DISALLOWED_ELEMENT_NAMES, true)) {
            return ImageValidationResult::invalid('svg_contains_disallowed_element');
        }

        $attributeResult = $this->scanAttributes($element);
        if ($attributeResult !== null) {
            return $attributeResult;
        }

        if ($localName === 'style') {
            return $this->scanCssText($element->textContent);
        }

        return null;
    }

    private function scanAttributes(\DOMElement $element): ?ImageValidationResult
    {
        foreach ($element->attributes as $attribute) {
            $attributeLocalName = strtolower($attribute->localName ?? $attribute->nodeName);
            $value = $attribute->value;

            // Catches every event-handler attribute (onload, onclick, ...)
            // regardless of quoting, since well-formed-XML parsing already
            // rejects unquoted attribute values outright.
            if (str_starts_with($attributeLocalName, 'on')) {
                return ImageValidationResult::invalid('svg_contains_event_attribute');
            }

            if ($attributeLocalName === 'style') {
                $styleResult = $this->scanCssText($value);
                if ($styleResult !== null) {
                    return $styleResult;
                }

                continue;
            }

            // Any presentation attribute (fill, stroke, mask, filter,
            // clip-path, cursor, marker-*, ...) may reference a resource via
            // a CSS url(...) function, so every attribute value is checked
            // generically rather than relying on an attribute-name allowlist.
            $urlFunctionResult = $this->scanValueForUnsafeUrlFunction($value);
            if ($urlFunctionResult !== null) {
                return $urlFunctionResult;
            }

            if (!in_array($attributeLocalName, self::URI_ATTRIBUTE_NAMES, true)) {
                continue;
            }

            $uriResult = $this->scanUriAttributeValue($value);
            if ($uriResult !== null) {
                return $uriResult;
            }
        }

        return null;
    }

    private function scanUriAttributeValue(string $value): ?ImageValidationResult
    {
        // Strip control characters/whitespace the way browsers normalise
        // scheme detection, so tricks like "java\tscript:" cannot bypass it.
        $normalized = preg_replace('/[\x00-\x20]/', '', $value) ?? '';

        if ($normalized === '' || str_starts_with($normalized, '#')) {
            return null;
        }

        if (preg_match('/^(javascript|vbscript|data):/i', $normalized) === 1) {
            return ImageValidationResult::invalid('svg_contains_unsafe_uri_scheme');
        }

        // Anything left over (absolute http(s), protocol-relative "//", any
        // other scheme, or a relative path) is not a same-document
        // reference and is therefore treated as an external reference.
        return ImageValidationResult::invalid('svg_contains_external_reference');
    }

    private function scanCssText(string $css): ?ImageValidationResult
    {
        if (preg_match('/@import\s+(?:url\()?["\']?(?:https?:)?\/\//i', $css) === 1) {
            return ImageValidationResult::invalid('svg_contains_external_reference');
        }

        return $this->scanValueForUnsafeUrlFunction($css);
    }

    /**
     * Detects a CSS url(...) function referencing an unsafe scheme or an
     * external location, wherever it appears: inside a <style> element,
     * inside a style="..." attribute, or inside any presentation attribute
     * (fill, stroke, mask, filter, clip-path, cursor, marker-*, ...) that
     * SVG allows to hold a url(#fragment) or url(<location>) reference.
     * A same-document url(#fragment) reference is left untouched.
     */
    private function scanValueForUnsafeUrlFunction(string $value): ?ImageValidationResult
    {
        if (preg_match('/url\(\s*["\']?\s*(javascript|vbscript|data):/i', $value) === 1) {
            return ImageValidationResult::invalid('svg_contains_unsafe_uri_scheme');
        }

        if (preg_match('/url\(\s*["\']?\s*(?:https?:)?\/\/[^)]*\)/i', $value) === 1) {
            return ImageValidationResult::invalid('svg_contains_external_reference');
        }

        return null;
    }
}
