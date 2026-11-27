<?php

declare(strict_types=1);

namespace App\Tests\Debts;

use App\Debts\Adapter\Messaging\ReadReceipt;
use App\Debts\Adapter\Messaging\ReadReceiptHandler;
use App\Debts\Application\Port\ReceiptReader;
use App\Debts\Application\Port\ReceiptStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ReceiptRecognitionCacheTest extends TestCase
{
    public function testOnlySuccessfulResultsAreSharedForTheSameOrderedPhotos(): void
    {
        $a = 'data:image/jpeg;base64,YQ==';
        $b = 'data:image/jpeg;base64,Yg==';
        $storage = $this->createMock(ReceiptStorage::class);
        $storage->method('claim')->willReturnOnConsecutiveCalls([$a, $b], [$a, $b], [$a, $b], [$b, $a]);
        $draft = ['lines' => [['name' => 'Coffee']]];
        $reader = $this->createMock(ReceiptReader::class);
        $reader->expects(self::exactly(3))->method('read')->willReturnOnConsecutiveCalls(null, $draft, $draft);
        $results = [];
        $storage->expects(self::exactly(4))->method('finish')->willReturnCallback(static function ($id, $attempt, $result) use (&$results): void {
            $results[$id] = $result;
        });
        $handler = new ReadReceiptHandler($storage, $reader, new ArrayAdapter());
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $handler(new ReadReceipt($id, $id, 'first'));
        }
        self::assertSame(['a' => null, 'b' => $draft, 'c' => $draft, 'd' => $draft], $results);
    }
}
