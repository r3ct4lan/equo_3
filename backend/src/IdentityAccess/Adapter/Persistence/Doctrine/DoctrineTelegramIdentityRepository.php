<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Login\LoginUserView;
use App\IdentityAccess\Application\Port\TelegramIdentityRepositoryPort;
use App\IdentityAccess\Domain\User\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use LogicException;

final readonly class DoctrineTelegramIdentityRepository implements TelegramIdentityRepositoryPort
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function lockIdentity(int $telegramId): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
            ['lock_key' => 'telegram-identity:'.$telegramId],
        );
    }

    public function accountForUpdate(int $telegramId): ?LoginUserView
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('user')->from(UserRecord::class, 'user')
            ->where('user.telegramId = :telegramId')->setParameter('telegramId', $telegramId)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        return $record instanceof UserRecord
            ? new LoginUserView($record->id(), $record->name(), $record->email(), $record->isActive(), $record->locale(), $record->currency())
            : null;
    }

    public function activate(string $userId): void
    {
        $user = $this->entityManager->find(UserRecord::class, $userId);
        if (!$user instanceof UserRecord) {
            throw new LogicException('Telegram account not found.');
        }

        $user->activate();
    }

    public function add(User $user): void
    {
        $this->entityManager->persist(new UserRecord(
            $user->id,
            $user->name,
            $user->email?->value,
            $user->passwordHash,
            $user->isActive(),
            $user->createdAt,
            locale: $user->locale,
            telegramId: $user->telegramId,
        ));
    }
}
