<?php

namespace App\Command;

use App\Service\ExtensionSnapshotLock;
use App\Service\GitHub\ApiException;
use App\Service\GitHub\RepositoryReference;
use App\Service\GitHub\SourcePersister;
use App\Service\GitHub\TargetedRepositoryLoader;
use App\Service\GitHub\TokenProvider;
use DateTime;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Targeted single-repository import/repair, reusing the same validation as
 * global discovery ({@see TargetedRepositoryLoader}) without running it.
 * Uses its own lock file so it can run independently of the global command.
 */
#[AsCommand(
    name: 'app:import-github-repository',
    description: 'Import or repair one targeted GitHub-hosted extension source by owner/repository',
)]
class ImportGithubRepositoryCommand extends Command
{
    private const DEFAULT_LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly TokenProvider $tokenProvider,
        private readonly TargetedRepositoryLoader $targetedRepositoryLoader,
        private readonly SourcePersister $persister,
        private readonly string $lockFilePath = __DIR__ . '/../../var/github-single-import.lock',
        private readonly int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('repository', InputArgument::REQUIRED, 'The target repository as owner/repository');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawArgument = (string) $input->getArgument('repository');
        $reference = RepositoryReference::fromFullName($rawArgument);
        if ($reference === null) {
            $io->error(sprintf('Expected a single argument in the form owner/repository, got: "%s".', $rawArgument));

            return Command::FAILURE;
        }

        $lock = new ExtensionSnapshotLock($this->lockFilePath);
        try {
            $lock->acquire($this->lockTimeoutSeconds);
        } catch (RuntimeException $exception) {
            $io->error(
                'Cannot acquire the GitHub import lock; another run is likely already in progress: '
                . $exception->getMessage()
            );

            return Command::FAILURE;
        }

        try {
            return $this->doExecute($reference, $io);
        } finally {
            $lock->release();
        }
    }

    private function doExecute(RepositoryReference $reference, SymfonyStyle $io): int
    {
        $token = $this->tokenProvider->getToken();
        if ($token === null) {
            $io->error(
                'GITHUB_TOKEN is missing or empty. Aborting before any GitHub API call: '
                . 'there is no unauthenticated fallback.'
            );

            return Command::FAILURE;
        }

        try {
            $result = $this->targetedRepositoryLoader->load($token, $reference->owner, $reference->repository);
            if (!$result->success) {
                $io->warning(sprintf('Skipped %s: %s', $reference->fullName(), $result->skipReason));

                return Command::SUCCESS;
            }

            $persistResult = $this->persister->persistCandidate($result->candidate, new DateTime());
            if (!$persistResult->success) {
                $io->warning(sprintf('Skipped %s: %s', $reference->fullName(), $persistResult->skipReason));

                return Command::SUCCESS;
            }

            $io->success(sprintf('Imported %s as a GitHub source.', $reference->fullName()));

            return Command::SUCCESS;
        } catch (ApiException $exception) {
            $io->error(
                $exception->isRateLimited()
                    ? 'GitHub API rate limit reached: ' . $exception->getMessage()
                    : 'GitHub API call failed: ' . $exception->getMessage()
            );

            return Command::FAILURE;
        }
    }
}
