<?php

declare(strict_types=1);

namespace App\Invitations\Adapter\Persistence\Doctrine;

use App\Invitations\Adapter\Persistence\Doctrine\Record\ConnectInvitationRecord;
use App\Invitations\Application\Port\InvitationRepositoryPort;
use App\Invitations\Domain\ConnectInvitation;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final readonly class DoctrineInvitationRepository implements InvitationRepositoryPort
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $id, bool $forUpdate = false): ?ConnectInvitation
    {
        $q = $this->em->createQueryBuilder()->select('i')->from(ConnectInvitationRecord::class, 'i')->where('i.id = :id')->setParameter('id', $id)->getQuery()->setHint(Query::HINT_REFRESH, true);
        if ($forUpdate) {
            $q->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $r = $q->getOneOrNullResult();

        return $r instanceof ConnectInvitationRecord ? $r->toDomain() : null;
    }

    public function available(string $authorId, DateTimeImmutable $now): ?ConnectInvitation
    {
        $r = $this->em->createQueryBuilder()->select('i')->from(ConnectInvitationRecord::class, 'i')->where('i.createdById = :author AND i.usedAt IS NULL AND i.expiresAt > :now')->setParameter('author', $authorId)->setParameter('now', $now)->orderBy('i.createdAt', 'DESC')->setMaxResults(1)->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return $r instanceof ConnectInvitationRecord ? $r->toDomain() : null;
    }

    public function save(ConnectInvitation $invitation): void
    {
        $record = $this->em->find(ConnectInvitationRecord::class, $invitation->id);
        if ($record instanceof ConnectInvitationRecord) {
            $record->update($invitation);
        } else {
            $this->em->persist(ConnectInvitationRecord::fromDomain($invitation));
        }
    }
}
