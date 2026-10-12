<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Replay;

use App\IdentityAccess\Application\Api\TokenDeliveryQuery;
use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmail;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class EmailOutboxReplay
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private TokenDeliveryQuery $tokenDelivery,
    ) {
    }

    public function replay(string $deliveryId): bool
    {
        return $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($deliveryId): bool {
            $outbox = $this->entityManager->find(EmailDeliveryOutboxRecord::class, $deliveryId, LockMode::PESSIMISTIC_WRITE);
            if (!$outbox instanceof EmailDeliveryOutboxRecord || EmailDeliveryStatus::Published !== $outbox->status() || null === $outbox->encryptedPayload()) {
                return false;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $state = $this->tokenDelivery->state($outbox->userActionTokenId(), $now);
            if (null === $state || !$state->isDeliverableAt($now)) {
                return false;
            }

            $this->messageBus->dispatch(new SendUserActionEmail($deliveryId));

            return true;
        });
    }
}
