<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\RefreshTokenCodec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RefreshTokenCodecTest extends TestCase
{
    private const string SESSION_ID = '87000000-0000-4000-8000-000000000001';

    public function testIssuesVersionedCredentialWithStableLocatorAndParsesDigest(): void
    {
        $codec = new RefreshTokenCodec();
        $issued = $codec->issueForSession(self::SESSION_ID);

        self::assertMatchesRegularExpression('/\Art2\.'.preg_quote(self::SESSION_ID, '/').'\.[A-Za-z0-9_-]{43}\z/', $issued->publicToken);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/', $issued->tokenHash);
        self::assertSame(self::SESSION_ID, $issued->sessionId);

        $parsed = $codec->parse($issued->publicToken);
        self::assertNotNull($parsed);
        self::assertSame(self::SESSION_ID, $parsed->sessionId);
        self::assertSame($issued->tokenHash, $parsed->tokenHash);
        self::assertNotSame($issued->publicToken, $issued->tokenHash);

        $segments = explode('.', $issued->publicToken);
        self::assertCount(3, $segments);
        $randomBytes = base64_decode(strtr($segments[2], '-_', '+/').'=', true);
        self::assertIsString($randomBytes);
        self::assertSame(32, strlen($randomBytes));
    }

    public function testRepeatedIssueKeepsLocatorAndChangesSecretAndDigest(): void
    {
        $codec = new RefreshTokenCodec();
        $first = $codec->issueForSession(self::SESSION_ID);
        $second = $codec->issueForSession(self::SESSION_ID);

        self::assertSame($first->sessionId, $second->sessionId);
        self::assertStringStartsWith('rt2.'.self::SESSION_ID.'.', $first->publicToken);
        self::assertStringStartsWith('rt2.'.self::SESSION_ID.'.', $second->publicToken);
        self::assertNotSame($first->publicToken, $second->publicToken);
        self::assertNotSame($first->tokenHash, $second->tokenHash);
    }

    public function testMalformedAndLegacyCredentialsAreRejectedWithoutDiagnostics(): void
    {
        $codec = new RefreshTokenCodec();
        $invalid = [
            'malformed',
            'rt.'.str_repeat('A', 43),
            'rt2.'.self::SESSION_ID,
            'rt2.'.self::SESSION_ID.'.'.str_repeat('A', 43).'.extra',
            'rt3.'.self::SESSION_ID.'.'.str_repeat('A', 43),
            'rt2.not-a-uuid.'.str_repeat('A', 43),
            'rt2.ABCDEFAB-CDEF-4ABC-8ABC-ABCDEFABCDEF.'.str_repeat('A', 43),
            'rt2.'.self::SESSION_ID.'.'.str_repeat('A', 42),
            'rt2.'.self::SESSION_ID.'.'.str_repeat('A', 44),
            'rt2.'.self::SESSION_ID.'.'.str_repeat('*', 43),
        ];

        foreach ($invalid as $credential) {
            self::assertNull($codec->parse($credential));
        }

        $invalidSessionId = 'not-a-session-id-secret';

        try {
            $codec->issueForSession($invalidSessionId);
            self::fail('Invalid session locator must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString($invalidSessionId, $exception->getMessage());
            self::assertStringNotContainsString('rt2.', $exception->getMessage());
        }
    }
}
