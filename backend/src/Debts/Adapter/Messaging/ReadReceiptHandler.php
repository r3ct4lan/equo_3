<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Messaging;

use App\Debts\Application\Port\ReceiptReader;
use App\Debts\Application\Port\ReceiptStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
final readonly class ReadReceiptHandler
{
    public function __construct(private ReceiptStorage $storage, private ReceiptReader $reader, #[Autowire(service: 'cache.receipt_recognition')] private CacheInterface $cache)
    {
    }

    public function __invoke(ReadReceipt $job): void
    {
        $photos = $this->storage->claim($job->uploadId, $job->owner, $job->attempt);
        if (null !== $photos) {
            $digests = [];
            foreach ($photos as $photo) {
                [, $encoded] = explode(',', $photo, 2);
                $digests[] = hash('sha256', base64_decode($encoded));
            }
            $key = 'receipt.v1.'.hash('sha256', implode('', $digests));
            $draft = $this->cache->get($key, function (ItemInterface $item, bool &$save) use ($photos): ?array {
                $result = $this->reader->read($photos);
                $save = !empty($result['lines']);
                $item->expiresAfter(86400);

                return $result;
            }, 0);
            $this->storage->finish($job->uploadId, $job->attempt, $draft);
        }
    }
}
