<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Connects\Application\Port\ConnectRuntimePort;
use App\Debts\Application\Port\DebtRuntimePort;
use App\Invitations\Application\Port\InvitationRuntimePort;
use App\Transfers\Application\Port\TransferRuntimePort;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ConnectRuntime implements ConnectRuntimePort, InvitationRuntimePort, DebtRuntimePort, TransferRuntimePort
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    public function newId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    public function run(callable $operation): mixed
    {
        return $this->em->wrapInTransaction(static fn (): mixed => $operation());
    }
}
