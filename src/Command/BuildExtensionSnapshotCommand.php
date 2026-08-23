<?php

namespace App\Command;

use App\Service\ExtensionSnapshotBuilder;
use App\Service\ExtensionSnapshotLock;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:build-extension-snapshot',
    description: 'Build and publish the public extensions snapshot'
)]
class BuildExtensionSnapshotCommand extends Command
{
    public function __construct(
        private ExtensionSnapshotBuilder $snapshotBuilder,
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Acquire lock to prevent concurrent snapshots
        $lockPath = $this->projectDir . '/var/snapshot.lock';
        $lock = new ExtensionSnapshotLock($lockPath);

        try {
            $lock->acquire();
        } catch (RuntimeException $e) {
            $io->error('Cannot acquire snapshot lock: ' . $e->getMessage());
            return Command::FAILURE;
        }

        try {
            $io->info('Building extension snapshot...');

            // Build the snapshot
            $json = $this->snapshotBuilder->buildToString();
            $io->success('Snapshot built successfully');

            // Publish to public directory
            $io->info('Publishing snapshot...');
            $this->snapshotBuilder->publish($json);

            $io->success('Snapshot published to public/data/extensions.json');
            $io->success('Versioned alias created at public/data/extensions.v2.json');

            // Build and publish comments snapshot
            $io->info('Building comments snapshot...');
            $commentsJson = $this->snapshotBuilder->buildCommentsToString();
            $io->success('Comments snapshot built successfully');

            $io->info('Publishing comments snapshot...');
            $this->snapshotBuilder->publishComments($commentsJson);
            $commentsSize = strlen($commentsJson);
            $io->success(sprintf(
                'Comments snapshot published to public/data/comments.json (%s)',
                $this->formatBytes($commentsSize)
            ));

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $io->error('Snapshot build failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
    }
}
