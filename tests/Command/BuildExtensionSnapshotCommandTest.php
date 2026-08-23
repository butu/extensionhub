<?php

namespace App\Tests\Command;

use App\Command\BuildExtensionSnapshotCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class BuildExtensionSnapshotCommandTest extends KernelTestCase
{
    /**
     * Test that the command creates the public/data/extensions.json file.
     */
    public function testCommandCreatesSnapshotFile(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:build-extension-snapshot');
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute([]);

        // Command should succeed
        self::assertSame(0, $statusCode);

        // Verify file was created
        $snapshotPath = $kernel->getProjectDir() . '/public/data/extensions.json';
        self::assertFileExists($snapshotPath);

        // Verify file contains valid JSON
        $json = file_get_contents($snapshotPath);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('schemaVersion', $payload);
    }

    /**
     * Test that the command returns 0 on success.
     */
    public function testCommandReturnsSuccessCode(): void
    {
        self::bootKernel();
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:build-extension-snapshot');
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute([]);

        self::assertSame(0, $statusCode);
    }

    /**
     * Test that the command also creates the versioned alias.
     */
    public function testCommandCreatesVersionedAlias(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:build-extension-snapshot');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        // Verify versioned file was created
        $versionedPath = $kernel->getProjectDir() . '/public/data/extensions.v2.json';
        self::assertFileExists($versionedPath);

        // Verify both files have the same content
        $mainPath = $kernel->getProjectDir() . '/public/data/extensions.json';
        $mainContent = file_get_contents($mainPath);
        $versionedContent = file_get_contents($versionedPath);

        self::assertSame($mainContent, $versionedContent);
    }

    /**
     * Test that the snapshot file is valid against the schema.
     */
    public function testCommandPublishesValidSnapshot(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:build-extension-snapshot');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $snapshotPath = $kernel->getProjectDir() . '/public/data/extensions.json';
        $json = file_get_contents($snapshotPath);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        // Verify basic structure matches schema expectations
        self::assertSame(2, $payload['schemaVersion']);
        self::assertSame(20, $payload['pageSize']);
        self::assertIsArray($payload['items']);
        self::assertSame(count($payload['items']), $payload['count']);
    }
}
