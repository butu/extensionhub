<?php

namespace App\Command;

use App\Service\EgoSourceBackfillService;
use DateTime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-ego-sources',
    description: 'One-time backfill of ExtensionSource/SourceMetricMeasurement rows from legacy EGO Extension data',
)]
class BackfillEgoSourcesCommand extends Command
{
    public function __construct(
        private readonly EgoSourceBackfillService $sourceBackfillService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->sourceBackfillService->backfillAll(new DateTime());

        $io->success(sprintf(
            'EGO source backfill done: %d extensions synced, %d skipped.',
            $report->getProcessedCount(),
            $report->getSkippedCount()
        ));

        foreach ($report->getSkipped() as $skipped) {
            $io->warning(sprintf('Extension #%s skipped: %s', $skipped['extensionId'] ?? 'unknown', $skipped['reason']));
        }

        return Command::SUCCESS;
    }
}
