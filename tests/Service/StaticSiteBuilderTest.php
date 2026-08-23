<?php

namespace App\Tests\Service;

use App\Service\StaticSiteBuilder;
use Pentatrion\ViteBundle\Service\EntrypointRenderer;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StaticSiteBuilderTest extends KernelTestCase
{
    /** @var string[] absolute paths created during a test, removed in tearDown */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            $this->removeRecursive($path);
        }
        $this->cleanupPaths = [];
        parent::tearDown();
    }

    private function builder(?string $projectDir = null): StaticSiteBuilder
    {
        self::bootKernel();
        $container = self::getContainer();
        $twig = $container->get(\Twig\Environment::class);

        return new StaticSiteBuilder(
            $twig,
            $projectDir ?? self::$kernel->getProjectDir(),
            $container->get(EntrypointRenderer::class)
        );
    }

    /** Builder wired to an isolated fixture project dir, never the real project. */
    private function fixtureBuilder(string $fixtureProjectDir): StaticSiteBuilder
    {
        return new StaticSiteBuilder(
            self::getContainer()->get(\Twig\Environment::class),
            $fixtureProjectDir,
            self::getContainer()->get(EntrypointRenderer::class)
        );
    }

    private function uniqueVarPath(string $prefix): string
    {
        $relative = 'var/' . $prefix . '-' . bin2hex(random_bytes(4));
        $this->cleanupPaths[] = self::$kernel->getProjectDir() . '/' . $relative;
        return $relative;
    }

    private function removeRecursive(string $path): void
    {
        if (is_link($path)) {
            unlink($path);
            return;
        }
        if (is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursive($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * Builds a minimal isolated fixture project directory under var/, used
     * for prerequisite-failure tests that must never touch the real
     * public/build or public/data directories.
     */
    private function createFixtureProjectDir(bool $extensionsMatch): string
    {
        $relative = $this->uniqueVarPath('static-site-fixture');
        $projectDir = self::$kernel->getProjectDir() . '/' . $relative;

        mkdir($projectDir . '/public/build/.vite', 0755, true);
        mkdir($projectDir . '/public/data', 0755, true);

        file_put_contents(
            $projectDir . '/public/build/.vite/entrypoints.json',
            json_encode(['entryPoints' => []], JSON_THROW_ON_ERROR)
        );

        file_put_contents($projectDir . '/public/data/extensions.json', '{"a":1}');
        file_put_contents(
            $projectDir . '/public/data/extensions.v2.json',
            $extensionsMatch ? '{"a":1}' : '{"a":2}'
        );
        file_put_contents($projectDir . '/public/data/comments.json', '{"comments":{}}');

        return $projectDir;
    }

    /**
     * Fixture with a hand-crafted extensions.json/.v2.json payload, used for
     * deterministic sitemap assertions independent of the real snapshot.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function createFixtureProjectDirWithExtensionItems(array $items): string
    {
        $relative = $this->uniqueVarPath('static-site-fixture-sitemap');
        $projectDir = self::$kernel->getProjectDir() . '/' . $relative;

        mkdir($projectDir . '/public/build/.vite', 0755, true);
        mkdir($projectDir . '/public/data', 0755, true);

        file_put_contents(
            $projectDir . '/public/build/.vite/entrypoints.json',
            json_encode(['entryPoints' => []], JSON_THROW_ON_ERROR)
        );

        $extensionsJson = json_encode(
            ['schemaVersion' => 2, 'items' => $items],
            JSON_THROW_ON_ERROR
        );
        file_put_contents($projectDir . '/public/data/extensions.json', $extensionsJson);
        file_put_contents($projectDir . '/public/data/extensions.v2.json', $extensionsJson);
        file_put_contents($projectDir . '/public/data/comments.json', '{"comments":{}}');

        return $projectDir;
    }

    /**
     * Clones the real entrypoints.json into an isolated fixture and creates
     * a placeholder file for every asset it references, so a full build can
     * succeed without depending on the real public/build directory's content.
     */
    private function createFixtureProjectDirWithRealEntrypoints(): string
    {
        $relative = $this->uniqueVarPath('static-site-fixture-real-assets');
        $projectDir = self::$kernel->getProjectDir() . '/' . $relative;

        mkdir($projectDir . '/public/build/.vite', 0755, true);
        mkdir($projectDir . '/public/data', 0755, true);

        $entrypointsRaw = file_get_contents(
            self::$kernel->getProjectDir() . '/public/build/.vite/entrypoints.json'
        );
        file_put_contents($projectDir . '/public/build/.vite/entrypoints.json', $entrypointsRaw);

        $entrypoints = json_decode($entrypointsRaw, true, 512, JSON_THROW_ON_ERROR);
        foreach ($entrypoints['entryPoints'] ?? [] as $entry) {
            foreach (['css', 'js', 'dynamic', 'preload'] as $type) {
                foreach ($entry[$type] ?? [] as $assetPath) {
                    $absolute = $projectDir . '/public' . $assetPath;
                    if (!is_dir(dirname($absolute))) {
                        mkdir(dirname($absolute), 0755, true);
                    }
                    file_put_contents($absolute, '');
                }
            }
        }

        file_put_contents($projectDir . '/public/data/extensions.json', '{"a":1}');
        file_put_contents($projectDir . '/public/data/extensions.v2.json', '{"a":1}');
        file_put_contents($projectDir . '/public/data/comments.json', '{"comments":{}}');

        return $projectDir;
    }

    /** Writes an isolated fixture with a hand-crafted entrypoints.json payload. */
    private function createFixtureProjectDirWithEntrypoints(array $entrypointsPayload): string
    {
        $relative = $this->uniqueVarPath('static-site-fixture-custom-entrypoints');
        $projectDir = self::$kernel->getProjectDir() . '/' . $relative;

        mkdir($projectDir . '/public/build/.vite', 0755, true);
        mkdir($projectDir . '/public/data', 0755, true);

        file_put_contents(
            $projectDir . '/public/build/.vite/entrypoints.json',
            json_encode($entrypointsPayload, JSON_THROW_ON_ERROR)
        );

        file_put_contents($projectDir . '/public/data/extensions.json', '{"a":1}');
        file_put_contents($projectDir . '/public/data/extensions.v2.json', '{"a":1}');
        file_put_contents($projectDir . '/public/data/comments.json', '{"comments":{}}');

        return $projectDir;
    }

    public function testRejectsAbsoluteOutputPath(): void
    {
        $builder = $this->builder();

        $this->expectException(RuntimeException::class);
        $builder->build('/etc/passwd-static-site');
    }

    public function testRejectsParentTraversalOutputPath(): void
    {
        $builder = $this->builder();

        $this->expectException(RuntimeException::class);
        $builder->build('var/../public/data');
    }

    public function testRejectsEmptyOutputPath(): void
    {
        $builder = $this->builder();

        $this->expectException(RuntimeException::class);
        $builder->build('');
    }

    public function testRejectsProjectRootAsOutputPath(): void
    {
        $builder = $this->builder();

        $this->expectException(RuntimeException::class);
        $builder->build('.');
    }

    public function testRejectsPublicBuildAsOutputPath(): void
    {
        $builder = $this->builder();

        $this->expectException(RuntimeException::class);
        $builder->build('public/build');
    }

    public function testRejectsPublicDataAsOutputPath(): void
    {
        $builder = $this->builder();

        $this->expectException(RuntimeException::class);
        $builder->build('public/data');
    }

    public function testForbiddenOutputPathDoesNotTouchRealPublicBuild(): void
    {
        self::bootKernel();
        $entrypointsPath = self::$kernel->getProjectDir() . '/public/build/.vite/entrypoints.json';
        $before = file_get_contents($entrypointsPath);

        $builder = $this->builder();
        try {
            $builder->build('public/build');
        } catch (RuntimeException $e) {
            // expected
        }

        self::assertSame($before, file_get_contents($entrypointsPath), 'public/build must remain untouched');
    }

    public function testPrerequisiteMismatchThrowsAndLeavesTargetUnchanged(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDir(extensionsMatch: false);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        // Pre-seed the target with sentinel content that must survive the failure.
        $targetRelative = 'var/target-' . bin2hex(random_bytes(4));
        $targetAbsolute = $fixtureProjectDir . '/' . $targetRelative;
        mkdir($targetAbsolute, 0755, true);
        file_put_contents($targetAbsolute . '/sentinel.txt', 'keep-me');

        $this->expectException(RuntimeException::class);
        try {
            $builder->build($targetRelative);
        } finally {
            self::assertFileExists($targetAbsolute . '/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($targetAbsolute . '/sentinel.txt'));
        }
    }

    public function testRejectsBarePublicOutputPathAndLeavesFixtureUntouched(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDir(extensionsMatch: true);
        file_put_contents($fixtureProjectDir . '/public/sentinel.txt', 'keep-me');

        $builder = $this->fixtureBuilder($fixtureProjectDir);

        try {
            $this->expectException(RuntimeException::class);
            $builder->build('public');
        } finally {
            self::assertFileExists($fixtureProjectDir . '/public/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($fixtureProjectDir . '/public/sentinel.txt'));
            self::assertFileExists($fixtureProjectDir . '/public/build/.vite/entrypoints.json');
            self::assertFileExists($fixtureProjectDir . '/public/data/extensions.json');
        }
    }

    public function testRejectsBareVarOutputPathAndLeavesFixtureUntouched(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDir(extensionsMatch: true);
        mkdir($fixtureProjectDir . '/var', 0755, true);
        file_put_contents($fixtureProjectDir . '/var/sentinel.txt', 'keep-me');

        $builder = $this->fixtureBuilder($fixtureProjectDir);

        try {
            $this->expectException(RuntimeException::class);
            $builder->build('var');
        } finally {
            self::assertFileExists($fixtureProjectDir . '/var/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($fixtureProjectDir . '/var/sentinel.txt'));
        }
    }

    public function testAllowsVarPagesDistOutputPath(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDir(extensionsMatch: true);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $builder->build('var/pages-dist');

        self::assertFileExists($fixtureProjectDir . '/var/pages-dist/index.html');
    }

    public function testAllowsDistOutputPath(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDir(extensionsMatch: true);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $builder->build('dist');

        self::assertFileExists($fixtureProjectDir . '/dist/index.html');
    }

    public function testBuildsExpectedStaticSiteStructure(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-build');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;

        self::assertFileExists($target . '/index.html');
        self::assertFileExists($target . '/404.html');
        self::assertFileExists($target . '/_redirects');
        self::assertFileExists($target . '/_headers');
        self::assertDirectoryExists($target . '/build');
        self::assertFileExists($target . '/build/.vite/entrypoints.json');
        self::assertDirectoryExists($target . '/data');

        // PWA installability: manifest and service worker must ship at the site root.
        self::assertFileExists($target . '/manifest.webmanifest');
        self::assertFileExists($target . '/sw.js');

        self::assertFileExists($target . '/sitemap.xml');
    }

    /**
     * Without its own exported document, `/use-the-data` would fall through to
     * the SPA 404 shell on static hosting.
     */
    public function testExportsUseTheDataPageAsOwnDocument(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-use-the-data');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        self::assertFileExists($target . '/use-the-data/index.html');

        $html = file_get_contents($target . '/use-the-data/index.html');
        self::assertStringContainsString('/data/extensions.v2.json', $html);

        // Booting the app JS without an #app mount would replace the page content.
        self::assertStringNotContainsString('id="app"', $html);
        self::assertStringNotContainsString('<script', $html);

        // Vite emits tags once per instance, so a second document silently
        // ends up unstyled without a reset.
        $entrypoints = json_decode(
            file_get_contents(self::$kernel->getProjectDir() . '/public/build/.vite/entrypoints.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($entrypoints['entryPoints'] as $entry) {
            foreach ($entry['css'] ?? [] as $assetPath) {
                self::assertStringContainsString($assetPath, $html);
            }
        }
    }

    public function testIndexAndNotFoundAreByteIdentical(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-identical');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        self::assertSame(
            file_get_contents($target . '/index.html'),
            file_get_contents($target . '/404.html')
        );
    }

    public function testRedirectsContainsSpaRoute(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-redirects');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        self::assertStringContainsString(
            '/extension/* /index.html 200',
            file_get_contents($target . '/_redirects')
        );
    }

    public function testHeadersContainsImmutableAndRevalidateRules(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-headers');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        $headers = file_get_contents($target . '/_headers');

        self::assertStringContainsString('/build/assets/*', $headers);
        self::assertStringContainsString('immutable', $headers);
        self::assertStringContainsString('/data/*', $headers);
        self::assertStringContainsString('must-revalidate', $headers);
    }

    public function testHtmlContainsRequiredMarkers(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-markers');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        $html = file_get_contents($target . '/index.html');

        self::assertStringContainsString('id="app"', $html);
        self::assertStringContainsString('/data/extensions.json', $html);

        $entrypoints = json_decode(
            file_get_contents(self::$kernel->getProjectDir() . '/public/build/.vite/entrypoints.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($entrypoints['entryPoints'] as $entry) {
            foreach (['css', 'js'] as $type) {
                foreach ($entry[$type] ?? [] as $assetPath) {
                    self::assertStringContainsString($assetPath, $html);
                }
            }
        }
    }

    public function testHtmlDoesNotContainTrailingWhitespace(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-whitespace');

        $builder->build($output);

        $html = file_get_contents(self::$kernel->getProjectDir() . '/' . $output . '/index.html');
        self::assertDoesNotMatchRegularExpression('/[ \t]+$/m', $html);
    }

    public function testDataDirectoryContainsExactlyThreeJsonFiles(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-data');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        $files = array_values(array_diff(scandir($target . '/data'), ['.', '..']));
        sort($files);

        self::assertSame(['comments.json', 'extensions.json', 'extensions.v2.json'], $files);
    }

    public function testSitemapContainsHomeAndUseTheDataUrls(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-sitemap-static-pages');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        $sitemap = file_get_contents($target . '/sitemap.xml');

        self::assertStringContainsString('<loc>https://extensionhub.pages.dev/</loc>', $sitemap);
        self::assertStringContainsString('<loc>https://extensionhub.pages.dev/use-the-data</loc>', $sitemap);
    }

    public function testSitemapIsWellFormedXml(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-sitemap-wellformed');

        $builder->build($output);

        $target = self::$kernel->getProjectDir() . '/' . $output;
        $sitemap = file_get_contents($target . '/sitemap.xml');

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($sitemap);
        libxml_use_internal_errors($previous);

        self::assertNotFalse($parsed, 'sitemap.xml must be well-formed XML');
    }

    public function testSitemapContainsExtensionUrlWithLastmodFromSnapshot(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithExtensionItems([
            [
                'uuid' => 'lockscreen-extension@pratap.fastmail.fm',
                'path' => '/extension/lockscreen-extension%40pratap.fastmail.fm',
                'updatedAt' => '2026-04-29T22:29:20+00:00',
            ],
        ]);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $builder->build('dist');

        $sitemap = file_get_contents($fixtureProjectDir . '/dist/sitemap.xml');

        self::assertStringContainsString(
            '<loc>https://extensionhub.pages.dev/extension/lockscreen-extension%40pratap.fastmail.fm</loc>',
            $sitemap
        );
        self::assertStringContainsString('<lastmod>2026-04-29T22:29:20+00:00</lastmod>', $sitemap);
    }

    public function testSitemapOmitsLastmodWhenSnapshotHasNoUpdatedAt(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithExtensionItems([
            ['uuid' => 'no-updated-at@example.com', 'path' => '/extension/no-updated-at%40example.com'],
        ]);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $builder->build('dist');

        $sitemap = file_get_contents($fixtureProjectDir . '/dist/sitemap.xml');
        $urlBlockStart = strpos($sitemap, '<loc>https://extensionhub.pages.dev/extension/no-updated-at%40example.com</loc>');
        self::assertNotFalse($urlBlockStart);

        $urlBlockEnd = strpos($sitemap, '</url>', $urlBlockStart);
        $urlBlock = substr($sitemap, $urlBlockStart, $urlBlockEnd - $urlBlockStart);
        self::assertStringNotContainsString('<lastmod>', $urlBlock);
    }

    public function testSitemapExcludesDataFilesAndUnknownUrls(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithExtensionItems([
            ['uuid' => 'known@example.com', 'path' => '/extension/known%40example.com', 'updatedAt' => '2026-01-01T00:00:00+00:00'],
        ]);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $builder->build('dist');

        $sitemap = file_get_contents($fixtureProjectDir . '/dist/sitemap.xml');

        // Old PK/slug URLs and /data/* files must never appear, only the
        // canonical UUID path taken directly from the snapshot.
        self::assertStringNotContainsString('/extension/7472', $sitemap);
        self::assertStringNotContainsString('/data/', $sitemap);
        self::assertStringNotContainsString('unknown-uuid', $sitemap);
    }

    public function testSitemapUpdatesWhenSnapshotChanges(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithExtensionItems([
            ['uuid' => 'old@example.com', 'path' => '/extension/old%40example.com', 'updatedAt' => '2025-01-01T00:00:00+00:00'],
        ]);
        $builder = $this->fixtureBuilder($fixtureProjectDir);
        $builder->build('dist');

        $firstSitemap = file_get_contents($fixtureProjectDir . '/dist/sitemap.xml');
        self::assertStringContainsString('/extension/old%40example.com', $firstSitemap);

        // Simulate the nightly snapshot swap: a fresh extensions.json/.v2.json.
        $updatedJson = json_encode(
            ['schemaVersion' => 2, 'items' => [
                ['uuid' => 'new@example.com', 'path' => '/extension/new%40example.com', 'updatedAt' => '2026-06-01T00:00:00+00:00'],
            ]],
            JSON_THROW_ON_ERROR
        );
        file_put_contents($fixtureProjectDir . '/public/data/extensions.json', $updatedJson);
        file_put_contents($fixtureProjectDir . '/public/data/extensions.v2.json', $updatedJson);

        $builder->build('dist');

        $secondSitemap = file_get_contents($fixtureProjectDir . '/dist/sitemap.xml');
        self::assertStringNotContainsString('/extension/old%40example.com', $secondSitemap);
        self::assertStringContainsString('/extension/new%40example.com', $secondSitemap);
    }

    public function testSwapReplacesExistingOutputAtomically(): void
    {
        $builder = $this->builder();
        $output = $this->uniqueVarPath('static-site-swap');
        $target = self::$kernel->getProjectDir() . '/' . $output;

        mkdir($target, 0755, true);
        file_put_contents($target . '/sentinel.txt', 'old-output');

        $builder->build($output);

        self::assertFileDoesNotExist($target . '/sentinel.txt');
        self::assertFileExists($target . '/index.html');
    }

    public function testBuildRejectsOversizedBuildAssetAndLeavesTargetUnchanged(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDir(extensionsMatch: true);

        // Sparse file just above the 25 MiB limit; ftruncate avoids writing real bytes.
        $handle = fopen($fixtureProjectDir . '/public/build/oversized.bin', 'c');
        ftruncate($handle, 25 * 1024 * 1024 + 1);
        fclose($handle);

        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $targetRelative = 'var/target-' . bin2hex(random_bytes(4));
        $targetAbsolute = $fixtureProjectDir . '/' . $targetRelative;
        mkdir($targetAbsolute, 0755, true);
        file_put_contents($targetAbsolute . '/sentinel.txt', 'keep-me');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/oversized\.bin/');
            $builder->build($targetRelative);
        } finally {
            self::assertFileExists($targetAbsolute . '/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($targetAbsolute . '/sentinel.txt'));
        }
    }

    public function testBuildRejectsTooManyFilesAndLeavesTargetUnchanged(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDir(extensionsMatch: true);

        for ($i = 0; $i <= 20000; $i++) {
            file_put_contents($fixtureProjectDir . '/public/build/f' . $i, '');
        }

        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $targetRelative = 'var/target-' . bin2hex(random_bytes(4));
        $targetAbsolute = $fixtureProjectDir . '/' . $targetRelative;
        mkdir($targetAbsolute, 0755, true);
        file_put_contents($targetAbsolute . '/sentinel.txt', 'keep-me');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/20.?000/');
            $builder->build($targetRelative);
        } finally {
            self::assertFileExists($targetAbsolute . '/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($targetAbsolute . '/sentinel.txt'));
        }
    }

    public function testBuildRejectsViteAssetPathOutsideBuildDirectory(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithEntrypoints([
            'entryPoints' => ['app' => ['css' => [], 'js' => ['/data/evil.js']]],
        ]);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $targetRelative = 'var/target-' . bin2hex(random_bytes(4));
        $targetAbsolute = $fixtureProjectDir . '/' . $targetRelative;
        mkdir($targetAbsolute, 0755, true);
        file_put_contents($targetAbsolute . '/sentinel.txt', 'keep-me');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/data\/evil\.js/');
            $builder->build($targetRelative);
        } finally {
            self::assertFileExists($targetAbsolute . '/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($targetAbsolute . '/sentinel.txt'));
        }
    }

    public function testBuildRejectsViteAssetPathContainingParentTraversal(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithEntrypoints([
            'entryPoints' => ['app' => ['css' => ['/build/../secrets.css'], 'js' => []]],
        ]);
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $targetRelative = 'var/target-' . bin2hex(random_bytes(4));
        $targetAbsolute = $fixtureProjectDir . '/' . $targetRelative;
        mkdir($targetAbsolute, 0755, true);
        file_put_contents($targetAbsolute . '/sentinel.txt', 'keep-me');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/secrets\.css/');
            $builder->build($targetRelative);
        } finally {
            self::assertFileExists($targetAbsolute . '/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($targetAbsolute . '/sentinel.txt'));
        }
    }

    public function testBuildRejectsMissingViteAssetFileAndLeavesTargetUnchanged(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithRealEntrypoints();

        $entrypoints = json_decode(
            file_get_contents($fixtureProjectDir . '/public/build/.vite/entrypoints.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $firstEntry = reset($entrypoints['entryPoints']);
        $missingAssetPath = $firstEntry['css'][0] ?? $firstEntry['js'][0] ?? null;
        self::assertNotNull($missingAssetPath, 'real entrypoints.json must reference at least one asset for this test');
        unlink($fixtureProjectDir . '/public' . $missingAssetPath);

        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $targetRelative = 'var/target-' . bin2hex(random_bytes(4));
        $targetAbsolute = $fixtureProjectDir . '/' . $targetRelative;
        mkdir($targetAbsolute, 0755, true);
        file_put_contents($targetAbsolute . '/sentinel.txt', 'keep-me');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/' . preg_quote($missingAssetPath, '/') . '/');
            $builder->build($targetRelative);
        } finally {
            self::assertFileExists($targetAbsolute . '/sentinel.txt');
            self::assertSame('keep-me', file_get_contents($targetAbsolute . '/sentinel.txt'));
        }
    }

    public function testBuildSucceedsWhenAllReferencedViteAssetsExist(): void
    {
        self::bootKernel();
        $fixtureProjectDir = $this->createFixtureProjectDirWithRealEntrypoints();
        $builder = $this->fixtureBuilder($fixtureProjectDir);

        $builder->build('dist');

        self::assertFileExists($fixtureProjectDir . '/dist/index.html');
    }
}
