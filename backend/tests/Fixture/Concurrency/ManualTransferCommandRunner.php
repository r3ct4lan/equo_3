<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Concurrency;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Kernel;
use App\Transfers\Application\CreateManualTransferCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class ManualTransferCommandRunner
{
    public static function main(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $c = $kernel->getContainer()->get('test.service_container');
        fwrite(STDOUT, (string) $c->get(Connection::class)->fetchOne('SELECT pg_backend_pid()')."\n");
        fflush(STDOUT);
        $input = json_decode((string) fgets(STDIN), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['author'] ?? null) || !is_string($input['connect'] ?? null) || !is_string($input['other'] ?? null) || !is_string($input['key'] ?? null) || !is_int($input['amount'] ?? null)) {
            exit(1);
        }
        fwrite(STDOUT, self::perform($c, ['author' => $input['author'], 'connect' => $input['connect'], 'other' => $input['other'], 'key' => $input['key'], 'amount' => $input['amount']])."\n");
        fflush(STDOUT);
        $kernel->shutdown();
    }

    /** @param array{author:string,connect:string,other:string,key:string,amount:int} $input */
    public static function perform(TestContainer $c, array $input): string
    {
        try {
            $r = $c->get('test.create_manual_transfer')->handle(new CreateManualTransferCommand($input['connect'], $input['author'], $input['other'], $input['amount'], $input['key']), $input['author']);

            return (string) $r['body']['id'];
        } catch (ApplicationFailure $e) {
            return $e->failureCode->value;
        }
    }
}
