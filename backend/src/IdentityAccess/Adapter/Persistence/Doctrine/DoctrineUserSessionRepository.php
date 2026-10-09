<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserSessionRecord;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
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

    public function findByIdForUpdate(string $sessionId): ?UserSession
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('session')
            ->from(UserSessionRecord::class, 'session')
            ->andWhere('session.id = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
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

    public function revokeAllUnfinishedForUser(string $userId, DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_session SET revoked_at = :now WHERE user_id = :userId AND revoked_at IS NULL AND expires_at > :now',
            ['userId' => $userId, 'now' => $now->format('Y-m-d H:i:s.uP')],
        );
    }
}
