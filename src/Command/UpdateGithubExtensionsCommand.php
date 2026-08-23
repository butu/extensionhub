<?php

namespace App\Command;

use App\Service\ExtensionSnapshotLock;
use App\Service\GitHub\ApiException;
use App\Service\GitHub\DiscoveryRunner;
use App\Service\GitHub\SourceRefreshRunner;
use App\Service\GitHub\TokenProvider;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Discovers and refreshes GitHub extension sources. Without options both
 * discovery and refresh run; --discover and --refresh each limit the run
 * to that single mode (passing both is equivalent to passing neither).
 *
 * A missing or empty GITHUB_TOKEN stops the command before any GitHub API
 * call is made: there is no unauthenticated fallback.
 *
 * An exclusive file lock prevents two runs of this command from ever
 * executing concurrently; a second run fails immediately instead of racing
 * the first one for the same GitHub sources.
 */
#[AsCommand(
    name: 'app:update-github-extensions',
    description: 'Discover and refresh GitHub-hosted GNOME Shell extension sources',
)]
class UpdateGithubExtensionsCommand extends Command
{
    private const DEFAULT_LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly TokenProvider $tokenProvider,
        private readonly DiscoveryRunner $discoveryRunner,
        private readonly SourceRefreshRunner $refreshRunner,
        private readonly string $lockFilePath = __DIR__ . '/../../var/github-update.lock',
        private readonly int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('discover', null, InputOption::VALUE_NONE, 'Only run candidate discovery')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Only refresh already-known GitHub sources');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = new ExtensionSnapshotLock($this->lockFilePath);
        try {
            $lock->acquire($this->lockTimeoutSeconds);
        } catch (RuntimeException $exception) {
            $io->error(
                'Cannot acquire the GitHub update lock; another run is likely already in progress: '
                . $exception->getMessage()
            );

            return Command::FAILURE;
        }

        try {
            return $this->doExecute($input, $io);
        } finally {
            $lock->release();
        }
    }

    private function doExecute(InputInterface $input, SymfonyStyle $io): int
    {
        $token = $this->tokenProvider->getToken();
        if ($token === null) {
            $io->error(
                'GITHUB_TOKEN is missing or empty. Aborting before any GitHub API call: '
                . 'there is no unauthenticated fallback.'
            );

            return Command::FAILURE;
        }

        $runDiscover = (bool) $input->getOption('discover');
        $runRefresh = (bool) $input->getOption('refresh');
        if (!$runDiscover && !$runRefresh) {
            $runDiscover = true;
            $runRefresh = true;
        }

        try {
            if ($runDiscover) {
                $result = $this->discoveryRunner->discover($token);
                $io->success(sprintf(
                    'Discovery processed %d unique repository candidate(s) across %d search %s: '
                    . '%d persisted, %d skipped.',
                    $result->uniqueRepositoryCount,
                    count($result->hitCountByQuery),
                    count($result->hitCountByQuery) === 1 ? 'query' : 'queries',
                    $result->persistedCount,
                    $result->skippedCount
                ));
            }

            if ($runRefresh) {
                $result = $this->refreshRunner->refresh($token);
                if ($result->knownSourceCount === 0) {
                    $io->note('Refresh: 0 known GitHub sources, nothing to do.');
                } else {
                    $io->success(sprintf(
                        'Refresh updated %d of %d known GitHub source(s), %d skipped.',
                        $result->refreshedCount,
                        $result->knownSourceCount,
                        $result->skippedCount
                    ));
                }
            }
        } catch (ApiException $exception) {
            $io->error(
                $exception->isRateLimited()
                    ? 'GitHub API rate limit reached. Aborting the run without partial updates: ' . $exception->getMessage()
                    : 'GitHub API call failed: ' . $exception->getMessage()
            );

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
