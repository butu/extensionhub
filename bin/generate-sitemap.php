#!/usr/bin/env php
<?php

// Standalone script for the nightly Cloudflare Pages deploy workflow, which
// only downloads snapshot JSON files and never runs `composer install`. It
// therefore requires the sitemap class file directly instead of going
// through Composer's autoloader, and must stay free of any other project
// dependency.
require __DIR__ . '/../src/Service/Sitemap/SitemapXmlGenerator.php';

use App\Service\Sitemap\SitemapXmlGenerator;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: generate-sitemap.php <extensions.json path> <sitemap.xml output path>\n");
    exit(1);
}

[, $extensionsPath, $outputPath] = $argv;

$raw = file_get_contents($extensionsPath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read {$extensionsPath}\n");
    exit(1);
}

try {
    $extensionsData = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "Invalid JSON in {$extensionsPath}: {$e->getMessage()}\n");
    exit(1);
}

if (!is_array($extensionsData)) {
    fwrite(STDERR, "{$extensionsPath} did not decode to a JSON object\n");
    exit(1);
}

$xml = SitemapXmlGenerator::generate($extensionsData);

if (file_put_contents($outputPath, $xml) === false) {
    fwrite(STDERR, "Cannot write {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, "Wrote {$outputPath}\n");
