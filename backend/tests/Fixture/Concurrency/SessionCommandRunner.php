<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Concurrency;

use App\IdentityAccess\Application\Activate\ActivateAccount;
use App\IdentityAccess\Application\Activate\ActivateCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Login\LoginCommand;
use App\IdentityAccess\Application\Login\LoginUser;
use App\IdentityAccess\Application\Logout\LogoutAllSessions;
use App\IdentityAccess\Application\Logout\LogoutCurrentSession;
use App\IdentityAccess\Application\Logout\LogoutCurrentSessionCommand;
use App\IdentityAccess\Application\PasswordChange\ChangePassword;
use App\IdentityAccess\Application\PasswordChange\ChangePasswordCommand;
use App\IdentityAccess\Application\PasswordReset\PasswordResetRequestCommand;
use App\IdentityAccess\Application\PasswordReset\RequestPasswordReset;
use App\IdentityAccess\Application\PasswordReset\ResetPassword;
use App\IdentityAccess\Application\PasswordReset\ResetPasswordCommand;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\IssuedAccessToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Application\Refresh\RefreshCommand;
use App\IdentityAccess\Application\Refresh\RefreshSession;
use App\Kernel;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Throwable;

final class SessionCommandRunner
{
    /** @param array{operation: string, user: string, refresh: string, csrf: string, token?: string, newPassword?: string} $input */
    public static function perform(TestContainer $container, array $input): string
    {
        $identities = $container->get(IdentityRepositoryPort::class);
        $sessions = $container->get(UserSessionRepositoryPort::class);
        $transaction = $container->get(TransactionPort::class);
        $clock = $container->get(ClockPort::class);
        $codec = $container->get(RefreshTokenCodecPort::class);
        $csrf = $container->get(CsrfTokenCodecPort::class);
        $issuer = new readonly class implements AccessTokenIssuerPort {
            public function issue(string $userId): IssuedAccessToken
            {
                return new IssuedAccessToken('test-only-access', 900);
            }
        };

        try {
            switch ($input['operation']) {
                case 'activate':
                    $container->get(ActivateAccount::class)->handle(new ActivateCommand($input['token'] ?? '', '192.0.2.83'));
                    break;
                case 'change':
                    $container->get(ChangePassword::class)->handle(new ChangePasswordCommand($input['user'], 'Correct password!', $input['newPassword'] ?? 'New password!'));
                    break;
                case 'reset':
                    $container->get(ResetPassword::class)->handle(new ResetPasswordCommand($input['token'] ?? '', $input['newPassword'] ?? 'New password!', '192.0.2.81'));
                    break;
                case 'request-reset':
                    $container->get(RequestPasswordReset::class)->handle(new PasswordResetRequestCommand($input['user'].'@example.test', '192.0.2.82'));
                    break;
                case 'refresh':
                    (new RefreshSession($sessions, $identities, $codec, $csrf, $issuer, $transaction, $clock))
                        ->handle(new RefreshCommand($input['refresh'], $input['csrf']));
                    break;
                case 'current':
                    (new LogoutCurrentSession($sessions, $codec, $csrf, $transaction, $clock))
                        ->handle(new LogoutCurrentSessionCommand($input['refresh'], $input['csrf']));
                    break;
                case 'all':
                    (new LogoutAllSessions($identities, $sessions, $transaction, $clock))->handle($input['user']);
                    break;
                case 'login':
                    (new LoginUser(
                        $identities, $container->get(PasswordHashingPort::class),
                        new \App\IdentityAccess\Application\Login\IssueUserSession($sessions, $issuer, $codec, $csrf, $container->get(UuidPort::class)),
                        $container->get(RateLimitPort::class), $transaction, $clock,
                    ))->handle(new LoginCommand($input['user'].'@example.test', 'Correct password!', '192.0.2.80'));
                    break;
                default:
                    throw new LogicException('Unknown test operation.');
            }
        } catch (ApplicationFailure $failure) {
            return $failure->failureCode->value;
        }

        return 'ok';
    }

    public static function main(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $connection = $container->get(Connection::class);
        $connection->executeStatement("SET lock_timeout = '5s'");
        $connection->executeStatement("SET statement_timeout = '10s'");
        echo $connection->fetchOne('SELECT pg_backend_pid()')."\n";
        fflush(STDOUT);
        $input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
        try {
            echo self::perform($container, $input)."\n";
        } catch (Throwable $failure) {
            echo $failure::class."\n";
            exit(1);
        }
    }
}
