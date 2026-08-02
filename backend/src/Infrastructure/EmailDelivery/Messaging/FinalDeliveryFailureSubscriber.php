<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Messaging;

use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final readonly class FinalDeliveryFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', -100]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof SendUserActionEmail) {
            return;
        }

        $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($message): void {
            $outbox = $this->entityManager->find(EmailDeliveryOutboxRecord::class, $message->deliveryId);

            if ($outbox instanceof EmailDeliveryOutboxRecord && !$outbox->isTerminal()) {
                $outbox->markFailed(
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    'SMTP delivery retries were exhausted.',
                );
                $this->entityManager->flush();
            }
        });
    }
}
