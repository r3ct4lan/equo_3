<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\EmailDelivery;

use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmail;
use App\Infrastructure\EmailDelivery\Monitoring\FailedEmailDeliveries;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class FailedEmailDeliveriesTest extends TestCase
{
    public function testListReturnsOnlyTransportAndDeliveryIdentifiers(): void
    {
        $deliveryId = '00000000-0000-4000-8000-000000000001';
        $receiver = new FakeListableTransport([
            (new Envelope(new SendUserActionEmail($deliveryId)))
                ->with(new TransportMessageIdStamp(45)),
            (new Envelope(new stdClass()))
                ->with(new TransportMessageIdStamp('unrelated')),
        ]);

        $result = (new FailedEmailDeliveries($receiver))->recent(20);

        self::assertSame([[
            'transport_message_id' => '45',
            'delivery_id' => $deliveryId,
        ]], $result);
    }
}

final class FakeListableTransport implements TransportInterface, ListableReceiverInterface
{
    /** @param list<Envelope> $envelopes */
    public function __construct(private array $envelopes)
    {
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }

    public function all(?int $limit = null): iterable
    {
        return null === $limit ? $this->envelopes : array_slice($this->envelopes, 0, $limit);
    }

    public function find(mixed $id): ?Envelope
    {
        return null;
    }
}
