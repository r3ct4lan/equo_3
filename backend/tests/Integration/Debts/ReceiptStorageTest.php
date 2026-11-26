<?php

declare(strict_types=1);

namespace App\Tests\Integration\Debts;

use App\Debts\Adapter\Persistence\Doctrine\DoctrineReceiptStorage;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Infrastructure\Retention\TechnicalDataCleanup;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReceiptStorageTest extends KernelTestCase
{
    public function testOnlyOwnerCanReadOrAttachAndCleanupOnlyRemovesOldDrafts(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $store = new DoctrineReceiptStorage($db);
            $owner = Uuid::v4()->toRfc4122();
            $other = Uuid::v4()->toRfc4122();
            foreach ([$owner, $other] as $id) {
                $db->insert('app_user', ['id' => $id, 'name' => 'User', 'email' => $id.'@example.test', 'password_hash' => 'test', 'created_at' => 'now', 'is_active' => 1]);
            }
            $images = [];
            foreach (['water', 'coffee', 'discount'] as $name) {
                $images[] = 'data:image/png;base64,'.base64_encode((string) file_get_contents(__DIR__.'/../../Debts/receipts/'.$name.'-1.png'));
            }
            $key = Uuid::v4()->toRfc4122();
            $upload = $store->prepare($owner, $key, $images);
            self::assertSame($upload, $store->prepare($owner, $key, $images));
            self::assertSame($images, $store->draftPhotos($upload, $owner));
            try {
                $store->draftPhotos($upload, $other);
                self::fail('Foreign draft visible');
            } catch (ApplicationFailure) {
                self::addToAssertionCount(1);
            }
            self::assertNull($store->claim($upload, $other, $key));
            self::assertSame($images, $store->claim($upload, $owner, $key));
            self::assertNull($store->claim($upload, $owner, $key));
            $store->finish($upload, $key, null);
            self::assertSame('failed', $store->status($upload, $owner)['state']);
            $retry = Uuid::v4()->toRfc4122();
            $store->retry($upload, $owner, $retry);
            self::assertSame($images, $store->claim($upload, $owner, $retry));
            $store->finish($upload, $key, null);
            $store->finish($upload, $retry, ['lines' => [['name' => 'Kafa']], 'currency' => null]);
            self::assertSame('ready', $store->status($upload, $owner)['state']);
            $draft = $store->status($upload, $owner)['draft'];
            self::assertIsArray($draft);
            self::assertArrayHasKey('currency', $draft);
            self::assertNull($draft['currency']);
            $debt = Uuid::v4()->toRfc4122();
            $db->insert('debt', ['id' => $debt, 'title' => 'Lunch', 'payer_id' => $owner, 'created_by_id' => $owner, 'total_amount' => 2400, 'created_at' => 'now']);
            try {
                $store->attach($upload, $other, $debt);
                self::fail('Foreign draft attached');
            } catch (ApplicationFailure) {
                self::addToAssertionCount(1);
            }
            $store->attach($upload, $owner, $debt);
            $expired = $store->prepare($other, $key, $images);
            $db->executeStatement("UPDATE receipt_upload SET created_at=now()-interval '2 days' WHERE id IN (?,?)", [$upload, $expired]);
            (new TechnicalDataCleanup($db))->run(10);
            self::assertSame([1, 2, 3], $store->photoNumbers($debt));
            self::assertFalse($db->fetchOne('SELECT id FROM receipt_upload WHERE id=?', [$expired]));
        } finally {
            $db->rollBack();
        }
    }
}
