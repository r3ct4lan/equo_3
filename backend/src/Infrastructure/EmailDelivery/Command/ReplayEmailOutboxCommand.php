<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Command;

use App\Infrastructure\EmailDelivery\Replay\EmailOutboxReplay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:email-outbox:replay', description: 'Safely republish one still-deliverable, non-terminal email delivery.')]
final class ReplayEmailOutboxCommand extends Command
{
    public function __construct(private readonly EmailOutboxReplay $replay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('delivery-id', InputArgument::REQUIRED, 'Email delivery UUID from the technical outbox.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deliveryId = $input->getArgument('delivery-id');
        if (!is_string($deliveryId) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $deliveryId)) {
            $output->writeln('<error>Delivery ID is invalid.</error>');

            return Command::INVALID;
        }

        if (!$this->replay->replay($deliveryId)) {
            $output->writeln('Replay refused: delivery is terminal or its action token is no longer deliverable.');

            return Command::FAILURE;
        }

        $output->writeln('Delivery was safely republished.');

        return Command::SUCCESS;
    }
}
