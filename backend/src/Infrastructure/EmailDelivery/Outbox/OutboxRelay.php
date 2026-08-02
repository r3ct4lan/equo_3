<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Outbox;

use App\IdentityAccess\Application\Api\TokenDeliveryQuery;
use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmail;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class OutboxRelay
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private TokenDeliveryQuery $tokenDelivery,
    ) {
    }

    public function relayBatch(int $limit = 50): int
    {
        $processed = 0;

        while ($processed < $limit && $this->relayNext()) {
            ++$processed;
        }

        return $processed;
    }

    private function relayNext(): bool
    {
        return $this->entityManager->getConnection()->transactional(function (Connection $connection): bool {
            $deliveryId = $this->entityManager->getConnection()->fetchOne(
                <<<'SQL'
                    SELECT id
                    FROM email_delivery_outbox
                    WHERE status = 'PENDING' AND available_at <= CURRENT_TIMESTAMP
                    ORDER BY available_at, created_at
                    FOR UPDATE SKIP LOCKED
                    LIMIT :limit
                    SQL,
                ['limit' => 1],
                ['limit' => ParameterType::INTEGER],
            );

            if (!is_string($deliveryId)) {
                return false;
            }

            $outbox = $this->entityManager->find(EmailDeliveryOutboxRecord::class, $deliveryId);

            if (!$outbox instanceof EmailDeliveryOutboxRecord || $outbox->isTerminal()) {
                return false;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $state = $this->tokenDelivery->state($outbox->userActionTokenId(), $now);

            if (null === $state || !$state->isDeliverableAt($now)) {
                $outbox->markFailed($now, 'Action token is no longer deliverable.');
                $this->entityManager->flush();

                return true;
            }

            $outbox->beginPublish();

            try {
                $this->messageBus->dispatch(new SendUserActionEmail($deliveryId));
                $outbox->markPublished($now);
            } catch (Throwable) {
                $outbox->deferAfterPublishFailure($now);
            }

            $this->entityManager->flush();

            return true;
        });
    }
}
