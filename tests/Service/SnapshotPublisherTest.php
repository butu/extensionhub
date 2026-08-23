<?php

namespace App\Tests\Service;

use App\Service\SnapshotPublisher;
use PHPUnit\Framework\TestCase;

/**
 * All fixtures live under a throwaway temp directory; public/data is never
 * touched by this test.
 */
class SnapshotPublisherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/snapshot-publisher-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testPublishWritesJsonPayloadToTargetPath(): void
    {
        $publisher = new SnapshotPublisher($this->tempDir);
        $json = json_encode(['schemaVersion' => 2, 'items' => []], JSON_THROW_ON_ERROR);

        $publisher->publish('public/data/extensions.json', $json);

        $targetPath = $this->tempDir . '/public/data/extensions.json';
        self::assertFileExists($targetPath);
        self::assertSame($json, file_get_contents($targetPath));
    }

    public function testPublishProducesByteIdenticalVersionedAliasWhenRequested(): void
    {
        $publisher = new SnapshotPublisher($this->tempDir);
        $json = json_encode(['schemaVersion' => 2, 'items' => [['uuid' => 'a@b']]], JSON_THROW_ON_ERROR);

        $publisher->publish('public/data/extensions.json', $json, 'public/data/extensions.v2.json');

        $mainPath = $this->tempDir . '/public/data/extensions.json';
        $aliasPath = $this->tempDir . '/public/data/extensions.v2.json';
        self::assertFileExists($mainPath);
        self::assertFileExists($aliasPath);
        self::assertSame(file_get_contents($mainPath), file_get_contents($aliasPath));
    }

    public function testPublishLeavesNoTemporaryArtifactOnSuccess(): void
    {
        $publisher = new SnapshotPublisher($this->tempDir);
        $json = json_encode(['schemaVersion' => 2, 'items' => []], JSON_THROW_ON_ERROR);

        $publisher->publish('public/data/extensions.json', $json, 'public/data/extensions.v2.json');

        self::assertFileDoesNotExist($this->tempDir . '/public/data/extensions.json.tmp');
    }

    public function testPublishCommentsWithoutAliasDoesNotCreateAlias(): void
    {
        $publisher = new SnapshotPublisher($this->tempDir);
        $json = json_encode(['schemaVersion' => 1, 'comments' => []], JSON_THROW_ON_ERROR);

        $publisher->publish('public/data/comments.json', $json);

        $targetPath = $this->tempDir . '/public/data/comments.json';
        self::assertFileExists($targetPath);
        self::assertSame($json, file_get_contents($targetPath));

        // No versioned alias was requested, so none of the plausible
        // comments alias names may exist.
        self::assertFileDoesNotExist($this->tempDir . '/public/data/comments.v1.json');
        self::assertFileDoesNotExist($this->tempDir . '/public/data/comments.v2.json');
        self::assertFileDoesNotExist($this->tempDir . '/public/data/comments.json.tmp');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
