<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Concurrency;

use App\Debts\Application\UpdateDebtCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class DebtUpdateRunner
{
    public static function main(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $c = $kernel->getContainer()->get('test.service_container');
        fwrite(STDOUT, (string) $c->get(Connection::class)->fetchOne('SELECT pg_backend_pid()')."\n");
        fflush(STDOUT);
        $input = json_decode((string) fgets(STDIN), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['author'] ?? null) || !is_string($input['debt'] ?? null) || !is_string($input['other'] ?? null) || !is_int($input['amount'] ?? null) || !is_int($input['version'] ?? null)) {
            exit(1);
        }
        fwrite(STDOUT, self::perform($c, ['author' => $input['author'], 'debt' => $input['debt'], 'other' => $input['other'], 'amount' => $input['amount'], 'version' => $input['version']])."\n");
        fflush(STDOUT);
        $kernel->shutdown();
    }

    /** @param array{author:string,debt:string,other:string,amount:int,version:int} $input */
    public static function perform(TestContainer $c, array $input): string
    {
        try {
            $r = $c->get('test.update_debt')->handle(new UpdateDebtCommand($input['debt'], 'Updated', null, $input['amount'], [$input['author'], $input['other']], true, $input['version']), $input['author']);

            return 'version:'.$r['version'];
        } catch (ApplicationFailure $e) {
            return $e->failureCode->value;
        }
    }
}
