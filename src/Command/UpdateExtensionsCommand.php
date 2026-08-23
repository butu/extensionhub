<?php

namespace App\Command;

use App\Repository\SourceMetricMeasurementRepository;
use App\Service\EgoExtensionImportService;
use DateTime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-extensions',
    description: 'Add a short description for your command',
)]
class UpdateExtensionsCommand extends Command
{
    public function __construct(
        private readonly EgoExtensionImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runMeasuredAt = new DateTime();

        $backfilledCreationDates = $this->importService->backfillMissingCreationDates();
        if ($backfilledCreationDates > 0) {
            $io->note('Backfilled ' . $backfilledCreationDates . ' missing creation dates.');
        }

        $result = $this->importService->importAll(
            $runMeasuredAt,
            static fn (string $extensionName) => $io->success('Extension ' . $extensionName . ' updated')
        );

        $io->note('Purged ' . $result->purgedDownloadMeasurements . ' download measurements older than 12 months.');
        $io->note(sprintf(
            'Purged %d source metric measurements older than %d days.',
            $result->purgedSourceMetricMeasurements,
            SourceMetricMeasurementRepository::RETENTION_DAYS
        ));

        return Command::SUCCESS;
    }
}
