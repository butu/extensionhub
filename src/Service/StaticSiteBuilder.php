<?php

namespace App\Service;

use App\Service\Sitemap\SitemapXmlGenerator;
use Pentatrion\ViteBundle\Service\EntrypointRenderer;
use RuntimeException;
use Twig\Environment;

/**
 * Builds a static snapshot of the extension listing page and swaps it into
 * place atomically, so a failed build never corrupts the existing output.
 */
final class StaticSiteBuilder
{
    private const MAX_FILE_SIZE_BYTES = 25 * 1024 * 1024;
    private const MAX_FILE_COUNT = 20000;

    private const ENTRYPOINTS_RELATIVE = 'public/build/.vite/entrypoints.json';
    private const DATA_DIR_RELATIVE = 'public/data';

    private const DATA_FILES = ['extensions.json', 'extensions.v2.json', 'comments.json'];

    /** Content pages exported as `{directory}/index.html` next to the app shell. */
    private const CONTENT_PAGES = ['use-the-data' => 'extension/use-the-data.html.twig'];

    /** Read as build inputs; replacing them would corrupt the project itself. */
    private const FORBIDDEN_OUTPUT_PATHS = [
        'public/build',
        'public/data',
        'src',
        'templates',
        'config',
        'vendor',
        'tests',
        'bin',
        'migrations',
        'assets',
        '.git',
        '.ddev',
        'node_modules',
    ];

    /** Whole-directory targets rejected only as an exact match; nested paths like var/pages-dist stay allowed. */
    private const FORBIDDEN_OUTPUT_PATHS_EXACT = ['public', 'var'];

    public function __construct(
        private Environment $twig,
        private string $projectDir,
        private EntrypointRenderer $entrypointRenderer,
    ) {}

    /**
     * @throws RuntimeException if the output is invalid or the build fails;
     *                          the previous output (if any) remains unchanged.
     */
    public function build(string $outputRelativePath): void
    {
        $targetDir = $this->resolveTargetDirectory($outputRelativePath);

        $prerequisites = $this->assertPrerequisites();
        $entrypoints = $prerequisites['entrypoints'];
        $this->assertViteAssetsExist($entrypoints);

        $html = $this->renderHtml();
        $this->assertHtmlContainsRequiredMarkers($html, $entrypoints);

        $contentPages = [];
        foreach (self::CONTENT_PAGES as $directory => $template) {
            $contentPages[$directory] = $this->renderTemplate($template);
        }

        $sitemapXml = SitemapXmlGenerator::generate($prerequisites['extensions']);

        $stagingDir = $this->createStagingDir($targetDir);

        try {
            $this->writeStaging($stagingDir, $html, $contentPages, $sitemapXml);
            $this->assertWithinLimits($stagingDir);
            $this->swap($stagingDir, $targetDir);
        } catch (\Throwable $e) {
            $this->deleteRecursive($stagingDir);
            throw $e;
        }
    }

    // Reject anything that could resolve outside the intended output directory.
    private function resolveTargetDirectory(string $outputRelativePath): string
    {
        $trimmed = trim($outputRelativePath);

        if ($trimmed === '') {
            throw new RuntimeException('Invalid --output path: must not be empty');
        }

        if (str_starts_with($trimmed, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $trimmed) === 1) {
            throw new RuntimeException("Invalid --output path '{$outputRelativePath}': absolute paths are not allowed");
        }

        $segments = explode('/', str_replace('\\', '/', $trimmed));
        $normalizedSegments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new RuntimeException("Invalid --output path '{$outputRelativePath}': '..' traversal is not allowed");
            }
            $normalizedSegments[] = $segment;
        }

        if ($normalizedSegments === []) {
            throw new RuntimeException("Invalid --output path '{$outputRelativePath}': resolves to the project root");
        }

        $normalizedRelative = implode('/', $normalizedSegments);

        if (in_array($normalizedRelative, self::FORBIDDEN_OUTPUT_PATHS_EXACT, true)) {
            throw new RuntimeException("Invalid --output path '{$outputRelativePath}': replacing the whole '{$normalizedRelative}' directory is not allowed");
        }

        foreach (self::FORBIDDEN_OUTPUT_PATHS as $forbidden) {
            if ($normalizedRelative === $forbidden || str_starts_with($normalizedRelative, $forbidden . '/')) {
                throw new RuntimeException("Invalid --output path '{$outputRelativePath}': '{$forbidden}' is a source directory and cannot be replaced");
            }
        }

        return $this->projectDir . '/' . $normalizedRelative;
    }

    /**
     * @return array{entrypoints: array<string, mixed>, extensions: array<string, mixed>}
     */
    private function assertPrerequisites(): array
    {
        $entrypoints = $this->readJsonFile($this->projectDir . '/' . self::ENTRYPOINTS_RELATIVE);

        $dataDir = $this->projectDir . '/' . self::DATA_DIR_RELATIVE;
        $contents = [];
        $decoded = [];
        foreach (self::DATA_FILES as $filename) {
            $path = $dataDir . '/' . $filename;
            $decoded[$filename] = $this->readJsonFile($path);
            $contents[$filename] = file_get_contents($path);
        }

        // extensions.v2.json is a stable-URL alias of extensions.json; if they
        // diverge, versioned and unversioned consumers would see different data.
        if ($contents['extensions.json'] !== $contents['extensions.v2.json']) {
            throw new RuntimeException(
                'Prerequisite check failed: public/data/extensions.json and extensions.v2.json are not byte-identical'
            );
        }

        return [
            'entrypoints' => $entrypoints,
            'extensions' => $decoded['extensions.json'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Prerequisite check failed: missing required file {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Prerequisite check failed: cannot read {$path}");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Prerequisite check failed: {$path} is not parseable JSON ({$e->getMessage()})");
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function renderHtml(): string
    {
        return $this->renderTemplate('extension/index.html.twig');
    }

    private function renderTemplate(string $template): string
    {
        // Vite emits each asset tag once per instance; without this reset only
        // the first document of a build would carry the stylesheet.
        $this->entrypointRenderer->reset();

        try {
            $html = $this->twig->render($template);

            return preg_replace('/[ \t]+$/m', '', $html) ?? $html;
        } catch (\Throwable $e) {
            throw new RuntimeException("Rendering {$template} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $entrypoints
     */
    private function assertHtmlContainsRequiredMarkers(string $html, array $entrypoints): void
    {
        if (!str_contains($html, 'id="app"')) {
            throw new RuntimeException('Rendered HTML is missing the required id="app" mount point');
        }

        if (!str_contains($html, '/data/extensions.json')) {
            throw new RuntimeException('Rendered HTML is missing the required /data/extensions.json reference');
        }

        foreach ($this->collectVitePaths($entrypoints) as $assetPath) {
            if (!str_contains($html, $assetPath)) {
                throw new RuntimeException("Rendered HTML is missing the resolved Vite asset path: {$assetPath}");
            }
        }
    }

    /**
     * @param array<string, mixed> $entrypoints
     * @return string[]
     */
    private function collectVitePaths(array $entrypoints): array
    {
        $paths = [];
        foreach ($entrypoints['entryPoints'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (['css', 'js', 'dynamic', 'preload'] as $type) {
                foreach ($entry[$type] ?? [] as $assetPath) {
                    if (is_string($assetPath)) {
                        $paths[] = $assetPath;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $entrypoints
     */
    // Vite may reference assets pruned or renamed since entrypoints.json was written; catch a stale build before staging.
    private function assertViteAssetsExist(array $entrypoints): void
    {
        foreach ($this->collectVitePaths($entrypoints) as $assetPath) {
            if (!str_starts_with($assetPath, '/build/') || str_contains($assetPath, '..')) {
                throw new RuntimeException("Invalid Vite asset path in entrypoints.json: {$assetPath}");
            }

            if (!is_file($this->projectDir . '/public' . $assetPath)) {
                throw new RuntimeException("Vite asset referenced in entrypoints.json is missing: {$assetPath}");
            }
        }
    }

    private function createStagingDir(string $targetDir): string
    {
        $parentDir = dirname($targetDir);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            throw new RuntimeException("Cannot create parent directory for staging: {$parentDir}");
        }

        $stagingDir = $parentDir . '/.staging-' . basename($targetDir) . '-' . bin2hex(random_bytes(6));
        if (!mkdir($stagingDir, 0755, true)) {
            throw new RuntimeException("Cannot create staging directory: {$stagingDir}");
        }

        return $stagingDir;
    }

    /**
     * @param array<string, string> $contentPages directory name => rendered HTML
     */
    private function writeStaging(string $stagingDir, string $html, array $contentPages, string $sitemapXml): void
    {
        $this->writeFile($stagingDir . '/index.html', $html);
        $this->writeFile($stagingDir . '/404.html', $html);
        $this->writeFile($stagingDir . '/sitemap.xml', $sitemapXml);

        // Static hosting resolves /use-the-data via /use-the-data/index.html.
        foreach ($contentPages as $directory => $pageHtml) {
            $pageDir = $stagingDir . '/' . $directory;
            if (!mkdir($pageDir, 0755, true) && !is_dir($pageDir)) {
                throw new RuntimeException("Cannot create content page directory: {$pageDir}");
            }
            $this->writeFile($pageDir . '/index.html', $pageHtml);
        }

        $this->writeFile($stagingDir . '/_redirects', "/extension/* /index.html 200\n");
        $this->writeFile($stagingDir . '/_headers', $this->buildHeadersContent());
        $sourceBuildDir = $this->projectDir . '/public/build';
        $this->copyDirectoryRecursive($sourceBuildDir, $stagingDir . '/build');

        // Static hosting does not serve the Symfony public directory directly.
        $sourceImagesDir = $this->projectDir . '/public/images';
        if (is_dir($sourceImagesDir)) {
            $this->copyDirectoryRecursive($sourceImagesDir, $stagingDir . '/images');
        }

        // PWA installability: ship the manifest and service worker at the export root when present.
        $this->copyFileIfExists('public/manifest.webmanifest', $stagingDir . '/manifest.webmanifest');
        $this->copyFileIfExists('public/sw.js', $stagingDir . '/sw.js');

        $sourceDataDir = $this->projectDir . '/' . self::DATA_DIR_RELATIVE;
        mkdir($stagingDir . '/data', 0755, true);
        foreach (self::DATA_FILES as $filename) {
            if (!copy($sourceDataDir . '/' . $filename, $stagingDir . '/data/' . $filename)) {
                throw new RuntimeException("Cannot copy public/data/{$filename} into staging");
            }
        }
    }

    private function buildHeadersContent(): string
    {
        return implode("\n", [
            '/build/assets/*',
            '  Cache-Control: public, max-age=31536000, immutable',
            '',
            '/*.html',
            '  Cache-Control: public, max-age=0, must-revalidate',
            '',
            '/data/*',
            '  Cache-Control: public, max-age=0, must-revalidate',
            '',
        ]);
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Cannot write staging file: {$path}");
        }
    }

    // Mirrors the optional public/images copy above: isolated test fixtures
    // intentionally omit these files, so absence here is not a build failure.
    private function copyFileIfExists(string $sourceRelativePath, string $destinationAbsolutePath): void
    {
        $sourceAbsolutePath = $this->projectDir . '/' . $sourceRelativePath;
        if (!is_file($sourceAbsolutePath)) {
            return;
        }

        if (!copy($sourceAbsolutePath, $destinationAbsolutePath)) {
            throw new RuntimeException("Cannot copy file into staging: {$sourceAbsolutePath}");
        }
    }

    private function copyDirectoryRecursive(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException("Cannot copy missing source directory: {$source}");
        }

        if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new RuntimeException("Cannot create directory: {$destination}");
        }

        foreach (scandir($source) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $destinationPath = $destination . '/' . $entry;

            if (is_dir($sourcePath)) {
                $this->copyDirectoryRecursive($sourcePath, $destinationPath);
                continue;
            }

            if (!copy($sourcePath, $destinationPath)) {
                throw new RuntimeException("Cannot copy file: {$sourcePath}");
            }
        }
    }

    // Cloudflare Pages deployment gates: 25 MiB per asset, 20000 files total.
    private function assertWithinLimits(string $stagingDir): void
    {
        $fileCount = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $fileCount++;
            if ($fileCount > self::MAX_FILE_COUNT) {
                throw new RuntimeException(
                    'Static site build exceeds the maximum allowed file count of ' . self::MAX_FILE_COUNT
                );
            }

            $size = $fileInfo->getSize();
            if ($size > self::MAX_FILE_SIZE_BYTES) {
                throw new RuntimeException(sprintf(
                    'File %s (%d bytes) exceeds the maximum allowed size of %d bytes',
                    $fileInfo->getPathname(),
                    $size,
                    self::MAX_FILE_SIZE_BYTES
                ));
            }
        }
    }

    // Moves the previous output aside first so a failed swap can roll back to it.
    private function swap(string $stagingDir, string $targetDir): void
    {
        if (!is_dir($targetDir)) {
            if (!rename($stagingDir, $targetDir)) {
                throw new RuntimeException("Cannot activate build output at {$targetDir}");
            }
            return;
        }

        $backupDir = dirname($targetDir) . '/.previous-' . basename($targetDir) . '-' . bin2hex(random_bytes(6));
        if (!rename($targetDir, $backupDir)) {
            throw new RuntimeException("Cannot move current output aside for swap: {$targetDir}");
        }

        if (!rename($stagingDir, $targetDir)) {
            // Never claim a successful rollback without verifying the restore rename.
            if (rename($backupDir, $targetDir)) {
                throw new RuntimeException("Cannot activate new build output at {$targetDir}; rolled back to previous state");
            }

            throw new RuntimeException(
                "Cannot activate new build output at {$targetDir} and rollback failed; "
                . "previous output is preserved at {$backupDir} for manual recovery"
            );
        }

        $this->deleteRecursive($backupDir);
    }

    private function deleteRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteRecursive($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
