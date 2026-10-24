<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Port\EmailChangeRepositoryPort;
use App\IdentityAccess\Application\Port\StoredEmailChangeToken;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use RuntimeException;

final readonly class DoctrineEmailChangeRepository implements EmailChangeRepositoryPort
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function accountForUpdate(string $userId): ?StoredLoginIdentity
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('user')->from(UserRecord::class, 'user')
            ->where('user.id = :id')->setParameter('id', $userId)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        return $user instanceof UserRecord
            ? new StoredLoginIdentity($user->id(), $user->name(), $user->email(), $user->passwordHash(), $user->isActive())
            : null;
    }

    public function emailExists(string $normalizedEmail): bool
    {
        return null !== $this->entityManager->getRepository(UserRecord::class)->findOneBy(['email' => $normalizedEmail]);
    }

    public function replaceToken(UserActionToken $token, array $payload, DateTimeImmutable $now): void
    {
        if (UserActionTokenPurpose::ChangeEmail !== $token->purpose || $payload !== ['newEmail' => $payload['newEmail']]) {
            throw new RuntimeException('Email change persistence payload is inconsistent.');
        }
        $user = $this->entityManager->find(UserRecord::class, $token->userId);
        if (!$user instanceof UserRecord) {
            throw new RuntimeException('Email change owner was not found.');
        }
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_action_token SET invalidated_at = :now WHERE user_id = :userId AND purpose = :purpose AND used_at IS NULL AND invalidated_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s.uP'), 'userId' => $token->userId, 'purpose' => $token->purpose->value],
        );
        $this->entityManager->persist(new UserActionTokenRecord($token->id, $user, $token->tokenHash, $token->purpose, $payload, $token->createdAt, $token->expiresAt));
    }

    public function userIdForTokenHash(string $tokenHash): ?string
    {
        $owner = $this->entityManager->getConnection()->fetchOne('SELECT user_id FROM user_action_token WHERE token_hash = ?', [$tokenHash]);

        return is_string($owner) ? $owner : null;
    }

    public function tokenForUpdate(string $tokenHash): ?StoredEmailChangeToken
    {
        $token = $this->entityManager->createQueryBuilder()
            ->select('token')->from(UserActionTokenRecord::class, 'token')
            ->where('token.tokenHash = :hash')->setParameter('hash', $tokenHash)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
        if (!$token instanceof UserActionTokenRecord) {
            return null;
        }

        return new StoredEmailChangeToken($token->id(), $token->user()->id(), $token->purpose(), $token->payload(), $token->expiresAt(), $token->usedAt(), $token->invalidatedAt());
    }

    public function completeChange(string $tokenId, string $userId, string $newEmail, DateTimeImmutable $now): void
    {
        $token = $this->entityManager->find(UserActionTokenRecord::class, $tokenId);
        $user = $this->entityManager->find(UserRecord::class, $userId);
        if (!$token instanceof UserActionTokenRecord || !$user instanceof UserRecord || $token->user()->id() !== $userId || UserActionTokenPurpose::ChangeEmail !== $token->purpose()) {
            throw new RuntimeException('Email change persistence state is inconsistent.');
        }
        $user->changeEmail($newEmail);
        $token->markUsed($now);
    }
}
