<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Concurrency;

use App\Connects\Application\CreateConnectCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class ConnectCommandRunner
{
    public static function main(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        fwrite(STDOUT, (string) $container->get(Connection::class)->fetchOne('SELECT pg_backend_pid()')."\n");
        fflush(STDOUT);
        $input = json_decode((string) fgets(STDIN), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['author'] ?? null) || !is_string($input['other'] ?? null) || !is_string($input['debt'] ?? null) || !is_string($input['key'] ?? null)) {
            exit(1);
        }
        fwrite(STDOUT, self::perform($container, ['author' => $input['author'], 'other' => $input['other'], 'debt' => $input['debt'], 'key' => $input['key']])."\n");
        fflush(STDOUT);
        $kernel->shutdown();
    }

    /** @param array{author:string, other:string, debt:string, key:string} $input */
    public static function perform(TestContainer $container, array $input): string
    {
        try {
            $result = $container->get('test.create_connect')->handle(new CreateConnectCommand($input['other'], $input['debt'], $input['key']), $input['author']);

            return (string) $result['body']['id'];
        } catch (ApplicationFailure $failure) {
            return $failure->failureCode->value;
        }
    }
}
