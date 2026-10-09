<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Persistence\Doctrine;

use App\IdentityAccess\Application\Port\EmailOutboxPort;
use App\IdentityAccess\Application\Port\PasswordResetOutboxPort;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEmailOutbox implements EmailOutboxPort, PasswordResetOutboxPort
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
        $this->enqueue($deliveryId, $tokenId, $recipientEmail, 'activate-account', $encryptedPayload, $now);
    }

    public function enqueuePasswordReset(string $deliveryId, string $tokenId, string $recipientEmail, string $encryptedPayload, DateTimeImmutable $now): void
    {
        $this->enqueue($deliveryId, $tokenId, $recipientEmail, 'reset-password', $encryptedPayload, $now);
    }

    private function enqueue(string $deliveryId, string $tokenId, string $recipientEmail, string $templateKey, string $encryptedPayload, DateTimeImmutable $now): void
    {
        $this->entityManager->persist(new EmailDeliveryOutboxRecord(
            $deliveryId,
            $tokenId,
            $recipientEmail,
            $templateKey,
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
