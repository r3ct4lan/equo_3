<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Monitoring;

use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmail;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final readonly class FailedEmailDeliveries
{
    public function __construct(private TransportInterface $failedTransport)
    {
    }

    /** @return list<array{transport_message_id: string, delivery_id: string}> */
    public function recent(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limit must be between 1 and 100.');
        }
        if (!$this->failedTransport instanceof ListableReceiverInterface) {
            throw new RuntimeException('Failure transport does not support listing.');
        }

        $deliveries = [];
        foreach ($this->failedTransport->all($limit) as $envelope) {
            $message = $envelope->getMessage();
            $transportId = $envelope->last(TransportMessageIdStamp::class)?->getId();
            if (!$message instanceof SendUserActionEmail || (!is_string($transportId) && !is_int($transportId))) {
                continue;
            }

            $deliveries[] = [
                'transport_message_id' => (string) $transportId,
                'delivery_id' => $message->deliveryId,
            ];
        }

        return $deliveries;
    }
}
