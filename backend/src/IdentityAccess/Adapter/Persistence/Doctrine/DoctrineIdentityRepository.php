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
use RuntimeException;

final readonly class DoctrineIdentityRepository implements IdentityRepositoryPort, ActivationRequestRepositoryPort
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
        $record = $this->entityManager->getRepository(UserRecord::class)->findOneBy(['email' => $normalizedEmail]);

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
        $record = $this->entityManager->find(UserRecord::class, $userId);

        if (!$record instanceof UserRecord) {
            return null;
        }

        return new CurrentUserState($record->id(), $record->isActive());
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

    public function addRegistration(User $user, UserActionToken $token): void
    {
        $record = new UserRecord(
            $user->id,
            $user->name,
            $user->email->value,
            $user->passwordHash,
            $user->isActive(),
            $user->createdAt,
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
        $record = $this->entityManager->createQueryBuilder()
            ->select('token', 'user')
            ->from(UserActionTokenRecord::class, 'token')
            ->innerJoin('token.user', 'user')
            ->andWhere('token.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if (!$record instanceof UserActionTokenRecord) {
            return null;
        }

        $this->entityManager->lock($record->user(), LockMode::PESSIMISTIC_WRITE);

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
