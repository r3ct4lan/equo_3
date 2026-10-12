<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Command;

use App\Infrastructure\EmailDelivery\Monitoring\FailedEmailDeliveries;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:email-outbox:failed', description: 'List safe identifiers for failed email delivery messages.')]
final class ListFailedEmailDeliveriesCommand extends Command
{
    public function __construct(private readonly FailedEmailDeliveries $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum failure messages to inspect.', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            $output->writeln('<error>Limit must be an integer between 1 and 100.</error>');

            return Command::INVALID;
        }

        try {
            $json = json_encode($this->deliveries->recent($limit), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException) {
            $output->writeln('<error>Failure transport request is invalid.</error>');

            return Command::INVALID;
        } catch (JsonException) {
            $output->writeln('<error>Failure message identifiers could not be encoded.</error>');

            return Command::FAILURE;
        }

        $output->writeln($json);

        return Command::SUCCESS;
    }
}
