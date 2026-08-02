<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\VersionedRequestFingerprint;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class VersionedRequestFingerprintTest extends TestCase
{
    private const string V1_KEY = 'aWRlbXBvdGVuY3ktZmluZ2VycHJpbnQtdGVzdC1rZXktMzI=';
    private const string V2_KEY = 'aWRlbXBvdGVuY3ktZmluZ2VycHJpbnQtdGVzdC1rZXktMzMh';

    public function testCreatesAStableVersionedHmacInsteadOfAPlainSha256Verifier(): void
    {
        $canonicalRequest = '{"email":"user@example.test","password":"A2345678901!"}';
        $fingerprint = new VersionedRequestFingerprint('v1', '{"v1":"'.self::V1_KEY.'"}');
        $created = $fingerprint->create($canonicalRequest);

        self::assertMatchesRegularExpression('/\Av1:[A-Za-z0-9_-]{43}\z/', $created);
        self::assertNotSame(hash('sha256', $canonicalRequest), $created);
        self::assertTrue($fingerprint->matches($created, $canonicalRequest));
        self::assertFalse($fingerprint->matches($created, $canonicalRequest.'changed'));
    }

    public function testRetainedKeyVerifiesAReplayAfterRotation(): void
    {
        $canonicalRequest = '{"request":"same"}';
        $v1 = new VersionedRequestFingerprint('v1', '{"v1":"'.self::V1_KEY.'"}');
        $rotated = new VersionedRequestFingerprint(
            'v2',
            '{"v1":"'.self::V1_KEY.'","v2":"'.self::V2_KEY.'"}',
        );

        $stored = $v1->create($canonicalRequest);

        self::assertTrue($rotated->matches($stored, $canonicalRequest));
        self::assertStringStartsWith('v2:', $rotated->create($canonicalRequest));
    }

    public function testAcceptsOnlyAnExistingLegacySha256RecordDuringCutover(): void
    {
        $canonicalRequest = '{"request":"legacy"}';
        $fingerprint = new VersionedRequestFingerprint('v1', '{"v1":"'.self::V1_KEY.'"}');

        self::assertTrue($fingerprint->matches(hash('sha256', $canonicalRequest), $canonicalRequest));
        self::assertFalse($fingerprint->matches(str_repeat('a', 64), $canonicalRequest));
    }

    public function testUnknownStoredKeyVersionFailsClosed(): void
    {
        $fingerprint = new VersionedRequestFingerprint('v1', '{"v1":"'.self::V1_KEY.'"}');

        $this->expectException(UnexpectedValueException::class);
        $fingerprint->matches('v0:'.str_repeat('A', 43), '{"request":"same"}');
    }
}
