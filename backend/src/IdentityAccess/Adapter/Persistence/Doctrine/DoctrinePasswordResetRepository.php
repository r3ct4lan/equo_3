<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Port\PasswordResetAccount;
use App\IdentityAccess\Application\Port\PasswordResetRepositoryPort;
use App\IdentityAccess\Application\Port\StoredPasswordResetToken;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use RuntimeException;

final readonly class DoctrinePasswordResetRepository implements PasswordResetRepositoryPort
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function accountForUpdate(string $normalizedEmail): ?PasswordResetAccount
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('user')->from(UserRecord::class, 'user')
            ->where('user.email = :email')->setParameter('email', $normalizedEmail)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        return $user instanceof UserRecord ? new PasswordResetAccount($user->id(), $user->email()) : null;
    }

    public function replaceToken(UserActionToken $token, DateTimeImmutable $now): void
    {
        if (UserActionTokenPurpose::ResetPassword !== $token->purpose) {
            throw new RuntimeException('Password reset persistence purpose is inconsistent.');
        }
        $user = $this->entityManager->find(UserRecord::class, $token->userId);
        if (!$user instanceof UserRecord) {
            throw new RuntimeException('Password reset owner was not found.');
        }
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_action_token SET invalidated_at = :now WHERE user_id = :userId AND purpose = :purpose AND used_at IS NULL AND invalidated_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s.uP'), 'userId' => $token->userId, 'purpose' => $token->purpose->value],
        );
        $this->entityManager->persist(new UserActionTokenRecord($token->id, $user, $token->tokenHash, $token->purpose, null, $token->createdAt, $token->expiresAt));
    }

    public function userIdForTokenHash(string $tokenHash): ?string
    {
        $owner = $this->entityManager->getConnection()->fetchOne('SELECT user_id FROM user_action_token WHERE token_hash = ?', [$tokenHash]);

        return is_string($owner) ? $owner : null;
    }

    public function tokenForUpdate(string $tokenHash): ?StoredPasswordResetToken
    {
        $token = $this->entityManager->createQueryBuilder()
            ->select('token')->from(UserActionTokenRecord::class, 'token')
            ->where('token.tokenHash = :hash')->setParameter('hash', $tokenHash)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
        if (!$token instanceof UserActionTokenRecord) {
            return null;
        }

        return new StoredPasswordResetToken($token->id(), $token->user()->id(), $token->purpose(), $token->expiresAt(), $token->usedAt(), $token->invalidatedAt());
    }

    public function completeReset(string $tokenId, string $userId, string $passwordHash, DateTimeImmutable $now): void
    {
        $token = $this->entityManager->find(UserActionTokenRecord::class, $tokenId);
        $user = $this->entityManager->find(UserRecord::class, $userId);
        if (!$token instanceof UserActionTokenRecord || !$user instanceof UserRecord || $token->user()->id() !== $userId || UserActionTokenPurpose::ResetPassword !== $token->purpose()) {
            throw new RuntimeException('Password reset persistence state is inconsistent.');
        }
        $user->replacePasswordHash($passwordHash);
        $token->markUsed($now);
    }
}
