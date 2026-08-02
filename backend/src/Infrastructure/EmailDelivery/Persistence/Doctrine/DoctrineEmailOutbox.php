<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Persistence\Doctrine;

use App\IdentityAccess\Application\Port\EmailOutboxPort;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEmailOutbox implements EmailOutboxPort
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function enqueueActivation(
        string $deliveryId,
        string $tokenId,
        string $recipientEmail,
        string $encryptedPayload,
        DateTimeImmutable $now,
    ): void {
        $this->entityManager->persist(new EmailDeliveryOutboxRecord(
            $deliveryId,
            $tokenId,
            $recipientEmail,
            'activate-account',
            $encryptedPayload,
            EmailDeliveryStatus::Pending,
            $now,
            $now,
            null,
            null,
            null,
            0,
            null,
        ));
    }
}
