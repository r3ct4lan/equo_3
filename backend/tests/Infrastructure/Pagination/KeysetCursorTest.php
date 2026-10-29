<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Pagination;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Infrastructure\Pagination\KeysetCursor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeysetCursorTest extends TestCase
{
    private const string ID = '00000000-0000-4000-8000-000000000001';

    public function testAbsentCursorStartsAtFirstPage(): void
    {
        self::assertSame(['before' => null, 'id' => null], (new KeysetCursor())->decode(null));
    }

    public function testRoundTripPreservesUtcOrderingWithoutPadding(): void
    {
        $cursor = new KeysetCursor();
        $time = new DateTimeImmutable('2026-10-29T02:30:45+02:00');
        $encoded = $cursor->encode($time, self::ID);
        $decoded = $cursor->decode($encoded);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $encoded);
        self::assertSame(self::ID, $decoded['id']);
        self::assertSame('2026-10-29T00:30:45+00:00', $decoded['before']?->format('c'));
        self::assertEquals($time, $decoded['before']);
    }

    #[DataProvider('invalidCursors')]
    public function testMalformedCursorReturnsOnlyThePublicFailure(string $value): void
    {
        try {
            (new KeysetCursor())->decode($value);
            self::fail('Malformed cursor was accepted.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::InvalidRequest, $failure->failureCode);
            self::assertSame('INVALID_REQUEST', $failure->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'oversized' => [str_repeat('a', 257)];
        yield 'padding' => ['e30='];
        yield 'whitespace' => ["e30\n"];
        yield 'non-url alphabet' => ['+/'];
        yield 'invalid base64 length' => ['a'];
        yield 'non-json' => [self::encode('private-cursor-content')];
        yield 'scalar' => [self::encode('42')];
        yield 'missing fields' => [self::encode('{}')];
        yield 'wrong field types' => [self::encode('{"createdAt":42,"id":false}')];
        yield 'malformed uuid' => [self::payload('2026-10-29T00:00:00Z', 'not-a-uuid')];
        yield 'uuid suffix' => [self::payload('2026-10-29T00:00:00Z', self::ID."\n")];
        yield 'uppercase uuid' => [self::payload('2026-10-29T00:00:00Z', 'abcdefAB-0000-4000-8000-000000000001')];
        yield 'invalid calendar day' => [self::payload('2026-02-30T00:00:00Z')];
        yield 'invalid hour' => [self::payload('2026-10-29T24:00:00Z')];
        yield 'leap second' => [self::payload('2026-10-29T23:59:60Z')];
        yield 'timezone offset' => [self::payload('2026-10-29T00:00:00+00:00')];
        yield 'fractional seconds' => [self::payload('2026-10-29T00:00:00.123Z')];
        yield 'trailing timestamp data' => [self::payload('2026-10-29T00:00:00Z private')];
        yield 'unparseable timestamp' => [self::payload('not-a-date')];
        yield 'excessive json nesting' => [self::encode('{"createdAt":[[[[[]]]]],"id":"'.self::ID.'"}')];
    }

    private static function payload(string $time, string $id = self::ID): string
    {
        return self::encode(json_encode(['createdAt' => $time, 'id' => $id], JSON_THROW_ON_ERROR));
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
