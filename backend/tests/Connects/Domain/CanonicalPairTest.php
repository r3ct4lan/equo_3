<?php

declare(strict_types=1);

namespace App\Tests\Connects\Domain;

use App\Connects\Domain\CanonicalPair;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CanonicalPairTest extends TestCase
{
    public function testDirectionAndCaseDoNotChangePair(): void
    {
        $a = 'aaaaaaaa-0000-4000-8000-000000000001';
        $b = 'bbbbbbbb-0000-4000-8000-000000000001';
        self::assertEquals(new CanonicalPair($a, $b), new CanonicalPair(strtoupper($b), $a));
    }

    public function testSelfPairIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CanonicalPair('aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000001');
    }

    public function testMalformedIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CanonicalPair('invalid', 'aaaaaaaa-0000-4000-8000-000000000001');
    }
}
