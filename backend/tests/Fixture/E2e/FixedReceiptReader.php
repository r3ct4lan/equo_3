<?php

declare(strict_types=1);

namespace App\Tests\Fixture\E2e;

use App\Debts\Application\Port\ReceiptReader;

final class FixedReceiptReader implements ReceiptReader
{
    public function read(array $images): ?array
    {
        $dir = __DIR__.'/../../Debts/receipts/';
        foreach (glob($dir.'*.json') ?: [] as $fixture) {
            $expected = [];
            foreach (glob(substr($fixture, 0, -5).'-*.png') ?: [] as $path) {
                $expected[] = 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
            }
            if ($images === $expected) {
                return json_decode((string) file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return null;
    }
}
