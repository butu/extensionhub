<?php

namespace App\Command;

use App\Service\StaticSiteBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:build-static-site',
    description: 'Build a fully static, deployable snapshot of the extension listing page'
)]
class BuildStaticSiteCommand extends Command
{
    public function __construct(
        private StaticSiteBuilder $staticSiteBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Project-relative output directory for the static site',
            'dist'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputPath = (string) $input->getOption('output');

        try {
            $io->info("Building static site into {$outputPath}...");
            $this->staticSiteBuilder->build($outputPath);
            $io->success("Static site published to {$outputPath}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Static site build failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
