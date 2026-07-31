<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record;

enum EmailDeliveryStatus: string
{
    case Pending = 'PENDING';
    case Published = 'PUBLISHED';
    case Sent = 'SENT';
    case Failed = 'FAILED';
}
