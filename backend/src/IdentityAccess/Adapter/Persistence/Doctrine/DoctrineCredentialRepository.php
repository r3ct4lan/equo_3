<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Port\CredentialAccount;
use App\IdentityAccess\Application\Port\CredentialRepositoryPort;
use App\IdentityAccess\Application\Port\SessionAccessPolicyPort;
use App\IdentityAccess\Application\Port\StoredEmailChangeToken;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use LogicException;

final readonly class DoctrineCredentialRepository implements CredentialRepositoryPort, SessionAccessPolicyPort
{
    public function __construct(private EntityManagerInterface $em, private DoctrineEmailChangeRepository $actions)
    {
    }

    public function allows(string $userId, ?string $sessionId): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT NOT u.strict_session_access OR EXISTS (
                SELECT 1 FROM user_session s WHERE s.id = :session AND s.user_id = u.id AND s.revoked_at IS NULL AND s.expires_at > CURRENT_TIMESTAMP
            ) FROM app_user u WHERE u.id = :user',
            ['user' => $userId, 'session' => $sessionId],
        );
    }

    public function activeSession(string $userId, string $sessionId, DateTimeImmutable $now): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM user_session WHERE id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > ?',
            [$sessionId, $userId, $now->format('Y-m-d H:i:s.uP')],
        );
    }

    public function accountForUpdate(string $userId): ?CredentialAccount
    {
        $user = $this->em->createQueryBuilder()
            ->select('u')->from(UserRecord::class, 'u')
            ->where('u.id = :id')->setParameter('id', $userId)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        return $user instanceof UserRecord
            ? new CredentialAccount($user->id(), $user->telegramId(), $user->isActive(), $user->locale(), $user->passwordIdentity())
            : null;
    }

    public function telegramOwner(int $telegramId): ?string
    {
        $owner = $this->em->getConnection()->fetchOne('SELECT id FROM app_user WHERE telegram_id = ?', [$telegramId]);

        return is_string($owner) ? $owner : null;
    }

    public function setTelegram(string $userId, int $telegramId): void
    {
        $this->user($userId)->changeTelegramId($telegramId);
    }

    public function setEmail(string $userId, string $email, string $passwordHash): void
    {
        $this->user($userId)->assignEmailCredentials($email, $passwordHash);
    }

    public function revokeOtherSessions(string $userId, ?string $preservedSession, DateTimeImmutable $now): void
    {
        $this->user($userId)->requireActiveAccessSession();
        $query = 'UPDATE user_session SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL';
        $parameters = ['now' => $now->format('Y-m-d H:i:s.uP'), 'user' => $userId];
        if (null !== $preservedSession) {
            $query .= ' AND id <> :preserved';
            $parameters['preserved'] = $preservedSession;
        }
        $this->em->getConnection()->executeStatement($query, $parameters);
    }

    public function replaceAction(UserActionToken $action, array $payload, DateTimeImmutable $now): void
    {
        $this->actions->storeToken($action, $payload, $now);
    }

    public function useAction(string $id, DateTimeImmutable $now): void
    {
        $record = $this->em->find(UserActionTokenRecord::class, $id);
        if (!$record instanceof UserActionTokenRecord) {
            throw new LogicException('Credential action not found.');
        }
        $record->markUsed($now);
    }

    public function currentAction(string $userId, string $sessionId, UserActionTokenPurpose $purpose): ?StoredEmailChangeToken
    {
        $hash = $this->em->getConnection()->fetchOne(
            "SELECT token_hash FROM user_action_token WHERE user_id = :user AND purpose = :purpose AND payload->>'sessionId' = :session AND used_at IS NULL AND invalidated_at IS NULL AND expires_at > CURRENT_TIMESTAMP",
            ['user' => $userId, 'purpose' => $purpose->value, 'session' => $sessionId],
        );

        return is_string($hash) ? $this->actions->tokenForUpdate($hash) : null;
    }

    public function cancelActions(string $userId, string $sessionId, DateTimeImmutable $now): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE user_action_token SET invalidated_at = :now WHERE user_id = :user AND payload->>'sessionId' = :session AND purpose IN ('LINK_EMAIL', 'MERGE_ACCOUNT') AND used_at IS NULL AND invalidated_at IS NULL",
            ['now' => $now->format('Y-m-d H:i:s.uP'), 'user' => $userId, 'session' => $sessionId],
        );
    }

    private function user(string $id): UserRecord
    {
        $user = $this->em->find(UserRecord::class, $id);
        if (!$user instanceof UserRecord) {
            throw new LogicException('Credential owner not found.');
        }

        return $user;
    }
}
