<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Concurrency;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class InvitationCommandRunner
{
    public static function main(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $c = $kernel->getContainer()->get('test.service_container');
        fwrite(STDOUT, (string) $c->get(Connection::class)->fetchOne('SELECT pg_backend_pid()')."\n");
        fflush(STDOUT);
        $input = json_decode((string) fgets(STDIN), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['operation'] ?? null) || !is_string($input['user'] ?? null) || !is_string($input['token'] ?? null)) {
            exit(1);
        }
        fwrite(STDOUT, self::perform($c, ['operation' => $input['operation'], 'user' => $input['user'], 'token' => $input['token']])."\n");
        fflush(STDOUT);
        $kernel->shutdown();
    }

    /** @param array{operation: string, user: string, token: string} $input */
    public static function perform(TestContainer $c, array $input): string
    {
        try {
            $service = $c->get('test.invitation_service');
            if ('create' === $input['operation']) {
                return hash('sha256', $service->create($input['user'])['token']);
            }
            $result = $service->accept($input['token'], $input['user'], '192.0.2.88');

            return (string) $result['view']['id'];
        } catch (ApplicationFailure $e) {
            return $e->failureCode->value;
        }
    }
}
