<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Messaging;

use App\Debts\Application\Port\ReceiptReader;
use App\Debts\Application\Port\ReceiptStorage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReadReceiptHandler
{
    public function __construct(private ReceiptStorage $storage, private ReceiptReader $reader)
    {
    }

    public function __invoke(ReadReceipt $job): void
    {
        $photos = $this->storage->claim($job->uploadId, $job->owner, $job->attempt);
        if (null !== $photos) {
            $this->storage->finish($job->uploadId, $job->attempt, $this->reader->read($photos));
        }
    }
}
