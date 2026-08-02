<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Command;

use App\Infrastructure\EmailDelivery\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:email-outbox:relay', description: 'Publish pending user-action email deliveries.')]
final class RelayEmailOutboxCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processed = $this->relay->relayBatch();
        $output->writeln(sprintf('Processed %d outbox deliveries.', $processed));

        return Command::SUCCESS;
    }
}
