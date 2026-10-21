<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Api\PendingInvitationAccess;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePendingInvitationAccess implements PendingInvitationAccess
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function forUser(string $userId): ?string
    {
        $user = $this->em->find(UserRecord::class, $userId);

        return $user instanceof UserRecord ? $user->pendingInvitationId() : null;
    }

    public function clear(string $userId): void
    {
        $user = $this->em->find(UserRecord::class, $userId);
        if ($user instanceof UserRecord) {
            $user->clearPendingInvitation();
            $this->em->flush();
        }
    }
}
