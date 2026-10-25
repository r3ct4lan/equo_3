<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Api\TokenDeliveryState;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\Authorization\ActivationTokenAccess;
use App\IdentityAccess\Application\Port\ActivationRequestAccount;
use App\IdentityAccess\Application\Port\ActivationRequestRepositoryPort;
use App\IdentityAccess\Application\Port\CurrentUserProfileRepositoryPort;
use App\IdentityAccess\Application\Port\CurrentUserState;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\StoredActivationToken;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use RuntimeException;

final readonly class DoctrineIdentityRepository implements IdentityRepositoryPort, ActivationRequestRepositoryPort, CurrentUserProfileRepositoryPort
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function emailExists(string $normalizedEmail): bool
    {
        return null !== $this->entityManager->getRepository(UserRecord::class)->findOneBy(['email' => $normalizedEmail]);
    }

    public function loginIdentityByEmail(string $normalizedEmail): ?StoredLoginIdentity
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('user')->from(UserRecord::class, 'user')
            ->where('user.email = :email')->setParameter('email', $normalizedEmail)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        if (!$record instanceof UserRecord) {
            return null;
        }

        return new StoredLoginIdentity(
            $record->id(),
            $record->name(),
            $record->email(),
            $record->passwordHash(),
            $record->isActive(),
        );
    }

    public function accountForUpdate(string $normalizedEmail): ?ActivationRequestAccount
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('user')
            ->from(UserRecord::class, 'user')
            ->andWhere('user.email = :email')
            ->setParameter('email', $normalizedEmail)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if (!$record instanceof UserRecord) {
            return null;
        }

        return new ActivationRequestAccount(
            $record->id(),
            $record->email(),
            $record->passwordHash(),
            $record->isActive(),
        );
    }

    public function currentUserState(string $userId): ?CurrentUserState
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('user')
            ->from(UserRecord::class, 'user')
            ->andWhere('user.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        if (!$record instanceof UserRecord) {
            return null;
        }

        return new CurrentUserState($record->id(), $record->isActive());
    }

    public function currentUserForUpdate(string $userId): ?CurrentUserState
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('user')
            ->from(UserRecord::class, 'user')
            ->andWhere('user.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $record instanceof UserRecord ? new CurrentUserState($record->id(), $record->isActive()) : null;
    }

    public function currentUserProfile(string $userId): ?UserView
    {
        $record = $this->entityManager->find(UserRecord::class, $userId);

        if (!$record instanceof UserRecord) {
            return null;
        }

        return new UserView(
            $record->id(),
            $record->name(),
            $record->email(),
            $record->isActive(),
            $record->createdAt(),
        );
    }

    public function renameCurrentUser(string $userId, string $name): void
    {
        $record = $this->entityManager->find(UserRecord::class, $userId);
        if (!$record instanceof UserRecord) {
            throw new RuntimeException('Current user was not found.');
        }

        $record->rename($name);
    }

    public function addRegistration(User $user, UserActionToken $token, ?string $pendingInvitationId = null): void
    {
        $record = new UserRecord(
            $user->id,
            $user->name,
            $user->email->value,
            $user->passwordHash,
            $user->isActive(),
            $user->createdAt,
            $pendingInvitationId,
        );
        $tokenRecord = new UserActionTokenRecord(
            $token->id,
            $record,
            $token->tokenHash,
            $token->purpose,
            null,
            $token->createdAt,
            $token->expiresAt,
        );

        $this->entityManager->persist($record);
        $this->entityManager->persist($tokenRecord);
    }

    public function replaceActivationToken(UserActionToken $token, DateTimeImmutable $invalidatedAt): void
    {
        $user = $this->entityManager->find(UserRecord::class, $token->userId);

        if (!$user instanceof UserRecord) {
            throw new RuntimeException('Activation request persistence state is inconsistent.');
        }

        $this->entityManager->createQueryBuilder()
            ->update(UserActionTokenRecord::class, 'token')
            ->set('token.invalidatedAt', ':invalidatedAt')
            ->andWhere('IDENTITY(token.user) = :userId')
            ->andWhere('token.purpose = :purpose')
            ->andWhere('token.usedAt IS NULL')
            ->andWhere('token.invalidatedAt IS NULL')
            ->setParameter('invalidatedAt', $invalidatedAt)
            ->setParameter('userId', $token->userId)
            ->setParameter('purpose', UserActionTokenPurpose::ActivateAccount->value)
            ->getQuery()
            ->execute();

        $this->entityManager->persist(new UserActionTokenRecord(
            $token->id,
            $user,
            $token->tokenHash,
            $token->purpose,
            null,
            $token->createdAt,
            $token->expiresAt,
        ));
    }

    public function activationTokenForUpdate(string $tokenHash): ?StoredActivationToken
    {
        $owner = $this->entityManager->getConnection()->fetchOne(
            'SELECT user_id FROM user_action_token WHERE token_hash = ?',
            [$tokenHash],
        );

        if (!is_string($owner) || null === $this->currentUserForUpdate($owner)) {
            return null;
        }

        $record = $this->entityManager->createQueryBuilder()
            ->select('token')
            ->from(UserActionTokenRecord::class, 'token')
            ->andWhere('token.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if (!$record instanceof UserActionTokenRecord || $record->user()->id() !== $owner) {
            return null;
        }

        return new StoredActivationToken(
            $record->id(),
            new ActivationTokenAccess(
                $record->user()->id(),
                $record->purpose(),
                $record->expiresAt(),
                $record->usedAt(),
                $record->invalidatedAt(),
            ),
            $record->user()->isActive(),
        );
    }

    public function activate(string $tokenId, string $userId, DateTimeImmutable $usedAt): void
    {
        $token = $this->entityManager->find(UserActionTokenRecord::class, $tokenId);
        $user = $this->entityManager->find(UserRecord::class, $userId);

        if (!$token instanceof UserActionTokenRecord || !$user instanceof UserRecord || $token->user()->id() !== $userId) {
            throw new RuntimeException('Activation persistence state is inconsistent.');
        }

        $user->activate();
        $token->markUsed($usedAt);
    }

    public function tokenDeliveryState(string $tokenId): ?TokenDeliveryState
    {
        $record = $this->entityManager->find(UserActionTokenRecord::class, $tokenId);

        if (!$record instanceof UserActionTokenRecord) {
            return null;
        }

        return new TokenDeliveryState(
            $record->purpose(),
            $record->expiresAt(),
            $record->usedAt(),
            $record->invalidatedAt(),
            null === $record->usedAt() && null === $record->invalidatedAt(),
        );
    }
}
