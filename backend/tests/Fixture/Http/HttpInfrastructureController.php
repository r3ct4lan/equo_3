<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Http;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserSessionRecord;
use App\IdentityAccess\Application\Login\LoginResult;
use App\IdentityAccess\Application\Login\LoginUserView;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryStatus;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class HttpInfrastructureController
{
    /** @return array{id: string|null, name: string|null} */
    public function payload(
        #[MapRequestPayload(acceptFormat: 'json')]
        FixtureRequest $payload,
    ): array {
        return [
            'id' => $payload->id,
            'name' => $payload->name,
        ];
    }

    public function response(): FixtureResponse
    {
        return new FixtureResponse(
            '550e8400-e29b-41d4-a716-446655440000',
            new DateTimeImmutable('2026-07-31T14:15:16+02:00'),
            FixtureStatus::Ready,
            new FixtureNestedResponse('nested'),
        );
    }

    public function error(): never
    {
        throw new RuntimeException('SQLSTATE password=secret at /var/www/backend/src/Internal.php:42');
    }

    /** @return array{user: UserRecord, token: UserActionTokenRecord, session: UserSessionRecord, identity: StoredLoginIdentity, login: LoginResult, outbox: EmailDeliveryOutboxRecord} */
    public function sensitiveRecords(): array
    {
        $createdAt = new DateTimeImmutable('2026-07-31T12:00:00Z');
        $user = new UserRecord(
            '550e8400-e29b-41d4-a716-446655440000',
            'Ada',
            'ada@example.test',
            'password-hash-must-not-leak',
            false,
            $createdAt,
        );
        $token = new UserActionTokenRecord(
            '550e8400-e29b-41d4-a716-446655440001',
            $user,
            'token-hash-must-not-leak',
            UserActionTokenPurpose::ActivateAccount,
            ['secret' => 'payload-must-not-leak'],
            $createdAt,
            $createdAt->modify('+24 hours'),
        );
        $session = new UserSessionRecord(
            '550e8400-e29b-41d4-a716-446655440003',
            $user,
            'refresh-hash-must-not-leak',
            $createdAt,
            $createdAt->modify('+30 days'),
        );
        $identity = new StoredLoginIdentity(
            $user->id(),
            $user->name(),
            $user->email(),
            'login-password-hash-must-not-leak',
            $user->isActive(),
        );
        $login = new LoginResult(
            'access-token-public-body-field',
            900,
            new LoginUserView($user->id(), $user->name(), $user->email(), true),
            'refresh-token-must-not-leak',
            'csrf-token-must-not-leak',
            $createdAt->modify('+30 days'),
        );
        $outbox = new EmailDeliveryOutboxRecord(
            '550e8400-e29b-41d4-a716-446655440002',
            $token->id(),
            $user->email(),
            'activate-account',
            'encrypted-payload-must-not-leak',
            EmailDeliveryStatus::Pending,
            $createdAt,
            $createdAt,
            null,
            null,
            null,
            0,
            null,
        );

        return [
            'user' => $user,
            'token' => $token,
            'session' => $session,
            'identity' => $identity,
            'login' => $login,
            'outbox' => $outbox,
        ];
    }
}
