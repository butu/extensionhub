<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class BuildStaticSiteCommandTest extends KernelTestCase
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

    private function removeRecursive(string $path): void
    {
        if (is_file($path) || is_link($path)) {
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

    private function uniqueVarPath(string $prefix): string
    {
        $relative = 'var/' . $prefix . '-' . bin2hex(random_bytes(4));
        $this->cleanupPaths[] = self::$kernel->getProjectDir() . '/' . $relative;
        return $relative;
    }

    public function testOutputOptionDefaultsToDist(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:build-static-site');
        $default = $command->getDefinition()->getOption('output')->getDefault();

        self::assertSame('dist', $default);
    }

    public function testCommandSucceedsAndCreatesExpectedFiles(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:build-static-site');
        $commandTester = new CommandTester($command);

        $output = $this->uniqueVarPath('cmd-static-site');
        $statusCode = $commandTester->execute(['--output' => $output]);

        self::assertSame(Command::SUCCESS, $statusCode);

        $target = $kernel->getProjectDir() . '/' . $output;
        self::assertFileExists($target . '/index.html');
        self::assertFileExists($target . '/404.html');
        self::assertDirectoryExists($target . '/build');
        self::assertDirectoryExists($target . '/data');
    }

    public function testCommandFailsForAbsoluteOutputPath(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:build-static-site');
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute(['--output' => '/etc/passwd-static-site']);

        self::assertSame(Command::FAILURE, $statusCode);
        self::assertStringContainsString('output', strtolower($commandTester->getDisplay()));
    }

    public function testCommandFailsForSourceDirectoryOutputAndLeavesItUntouched(): void
    {
        $kernel = self::bootKernel();
        $entrypointsPath = $kernel->getProjectDir() . '/public/build/.vite/entrypoints.json';
        $before = file_get_contents($entrypointsPath);

        $application = new Application($kernel);
        $command = $application->find('app:build-static-site');
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute(['--output' => 'public/build']);

        self::assertSame(Command::FAILURE, $statusCode);
        self::assertSame($before, file_get_contents($entrypointsPath));
    }
}
