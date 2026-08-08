<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserSessionRecord;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Domain\Access\UserSession;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineUserSessionRepository implements UserSessionRepositoryPort
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(UserSession $session): void
    {
        $user = $this->entityManager->find(UserRecord::class, $session->userId);

        if (!$user instanceof UserRecord) {
            throw new RuntimeException('User session owner was not found.');
        }

        $this->entityManager->persist(new UserSessionRecord(
            $session->id,
            $user,
            $session->refreshTokenHash(),
            $session->createdAt,
            $session->expiresAt,
            $session->revokedAt(),
        ));
    }

    public function findByRefreshTokenHashForUpdate(string $refreshTokenHash): ?UserSession
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('session', 'user')
            ->from(UserSessionRecord::class, 'session')
            ->innerJoin('session.user', 'user')
            ->andWhere('session.refreshTokenHash = :refreshTokenHash')
            ->setParameter('refreshTokenHash', $refreshTokenHash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if (!$record instanceof UserSessionRecord) {
            return null;
        }

        return $this->toDomain($record);
    }

    public function save(UserSession $session): void
    {
        $record = $this->entityManager->find(UserSessionRecord::class, $session->id);

        if (!$record instanceof UserSessionRecord || $record->user()->id() !== $session->userId) {
            throw new RuntimeException('User session persistence state is inconsistent.');
        }

        $record->replaceRefreshTokenHash($session->refreshTokenHash());
        $record->revoke($session->revokedAt());
    }

    private function toDomain(UserSessionRecord $record): UserSession
    {
        return UserSession::rehydrate(
            $record->id(),
            $record->user()->id(),
            $record->refreshTokenHash(),
            $record->createdAt(),
            $record->expiresAt(),
            $record->revokedAt(),
        );
    }
}
