<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Port\PasswordChangeRepositoryPort;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use RuntimeException;

final readonly class DoctrinePasswordChangeRepository implements PasswordChangeRepositoryPort
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

    public function replacePasswordHash(string $userId, string $passwordHash): void
    {
        $user = $this->entityManager->find(UserRecord::class, $userId);
        if (!$user instanceof UserRecord) {
            throw new RuntimeException('Password change owner was not found.');
        }
        $user->replacePasswordHash($passwordHash);
    }
}
