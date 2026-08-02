<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Messaging;

final readonly class SendUserActionEmail
{
    public function __construct(public string $deliveryId)
    {
    }
}
