<?php

declare(strict_types=1);

namespace App\Infrastructure\Retention\Command;

use App\Infrastructure\Retention\TechnicalDataCleanup;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:retention:cleanup', description: 'Clean expired technical records using the MVP retention periods.')]
final class CleanupTechnicalDataCommand extends Command
{
    public function __construct(private readonly TechnicalDataCleanup $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Maximum rows processed per record type.', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print eligible row counts without changing data.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT);
        if (!is_int($batchSize) || $batchSize < 1 || $batchSize > 5000) {
            $output->writeln('<error>Batch size must be an integer between 1 and 5000.</error>');

            return Command::INVALID;
        }

        try {
            $counts = $this->cleanup->run($batchSize, (bool) $input->getOption('dry-run'));
        } catch (InvalidArgumentException) {
            $output->writeln('<error>Cleanup request is invalid.</error>');

            return Command::INVALID;
        }

        if (($counts['skipped'] ?? 0) === 1) {
            $output->writeln('Cleanup skipped: another cleanup run holds the lock.');

            return Command::SUCCESS;
        }

        $label = (bool) $input->getOption('dry-run') ? 'Eligible' : 'Processed';
        foreach ($counts as $recordType => $count) {
            $output->writeln(sprintf('%s %s: %d', $label, $recordType, $count));
        }

        return Command::SUCCESS;
    }
}
