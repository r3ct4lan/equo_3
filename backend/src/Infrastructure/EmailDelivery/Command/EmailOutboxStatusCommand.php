<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Command;

use App\Infrastructure\EmailDelivery\Monitoring\EmailOutboxAlertThresholds;
use App\Infrastructure\EmailDelivery\Monitoring\EmailOutboxMonitor;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:email-outbox:status', description: 'Print aggregate email delivery backlog and failure metrics.')]
final class EmailOutboxStatusCommand extends Command
{
    public function __construct(
        private readonly EmailOutboxMonitor $monitor,
        private readonly EmailOutboxAlertThresholds $thresholds,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $metrics = $this->monitor->snapshot();
            $evaluation = $this->thresholds->evaluate($metrics);
            $json = json_encode(
                [...$metrics, 'alert_status' => $evaluation['status'], 'alerts' => $evaluation['alerts']],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            $output->writeln('<error>Email delivery metrics could not be encoded.</error>');

            return Command::FAILURE;
        }

        $output->writeln($json);

        return match ($evaluation['status']) {
            'warning' => 1,
            'critical' => 2,
            default => Command::SUCCESS,
        };
    }
}
