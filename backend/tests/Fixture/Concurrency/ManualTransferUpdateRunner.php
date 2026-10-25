<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Concurrency;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Kernel;
use App\Transfers\Application\UpdateManualTransferCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class ManualTransferUpdateRunner
{
    public static function main(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $c = $kernel->getContainer()->get('test.service_container');
        fwrite(STDOUT, (string) $c->get(Connection::class)->fetchOne('SELECT pg_backend_pid()')."\n");
        fflush(STDOUT);
        $input = json_decode((string) fgets(STDIN), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['author'] ?? null) || !is_string($input['transfer'] ?? null) || !is_string($input['sender'] ?? null) || !is_string($input['receiver'] ?? null) || !is_int($input['amount'] ?? null) || !is_int($input['version'] ?? null)) {
            exit(1);
        }
        fwrite(STDOUT, self::perform($c, ['author' => $input['author'], 'transfer' => $input['transfer'], 'sender' => $input['sender'], 'receiver' => $input['receiver'], 'amount' => $input['amount'], 'version' => $input['version']])."\n");
        fflush(STDOUT);
        $kernel->shutdown();
    }

    /** @param array{author:string,transfer:string,sender:string,receiver:string,amount:int,version:int} $input */
    public static function perform(TestContainer $c, array $input): string
    {
        try {
            $result = $c->get('test.update_manual_transfer')->handle(new UpdateManualTransferCommand($input['transfer'], $input['sender'], $input['receiver'], $input['amount'], $input['version']), $input['author']);

            return 'version:'.$result['version'];
        } catch (ApplicationFailure $e) {
            return $e->failureCode->value;
        }
    }
}
