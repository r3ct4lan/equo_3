<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Command;

use App\IdentityAccess\Adapter\Security\TelegramDeploymentConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:telegram:check-configuration', description: 'Validate Telegram deployment settings without printing credentials.')]
final class CheckTelegramConfigurationCommand extends Command
{
    public function __construct(private readonly TelegramDeploymentConfiguration $configuration)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $this->configuration->problems();
        if ([] !== $errors) {
            foreach ($errors as $error) {
                $output->writeln('<error>'.$error.'</error>');
            }

            return Command::FAILURE;
        }

        $output->writeln('Telegram configuration is valid.');

        return Command::SUCCESS;
    }
}
