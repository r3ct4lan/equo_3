<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\VersionedCsrfTokenCodec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VersionedCsrfTokenCodecTest extends TestCase
{
    private const string SESSION_ID = '952adfb3-c5f6-4bd9-87cb-405d456a2f9a';
    private const string OTHER_SESSION_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const string V1_KEY = 'Y3NyZi10b2tlbi1oYW1jLWRldi12MS1rZXktMzItYnl0ZXMh';
    private const string V2_KEY = 'Y3NyZi10b2tlbi1oYW1jLWRldi12Mi1rZXktMzItYnl0ZXMh';

    public function testIssuesAndVerifiesSessionBoundToken(): void
    {
        $codec = new VersionedCsrfTokenCodec('v1', '{"v1":"'.self::V1_KEY.'"}');
        $token = $codec->issue(self::SESSION_ID);

        self::assertMatchesRegularExpression('/\Av1\.[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}\z/', $token);
        self::assertTrue($codec->verify(self::SESSION_ID, $token));
        self::assertFalse($codec->verify(self::OTHER_SESSION_ID, $token));
    }

    public function testTamperedNonceSignatureMalformedAndUnknownVersionAreRejected(): void
    {
        $codec = new VersionedCsrfTokenCodec('v1', '{"v1":"'.self::V1_KEY.'"}');
        $token = $codec->issue(self::SESSION_ID);
        $parts = explode('.', $token);

        $tamperedNonce = $parts[0].'.'.self::changeFirstCharacter($parts[1]).'.'.$parts[2];
        $tamperedSignature = $parts[0].'.'.$parts[1].'.'.self::changeFirstCharacter($parts[2]);

        self::assertFalse($codec->verify(self::SESSION_ID, $tamperedNonce));
        self::assertFalse($codec->verify(self::SESSION_ID, $tamperedSignature));
        self::assertFalse($codec->verify(self::SESSION_ID, 'malformed'));
        self::assertFalse($codec->verify(self::SESSION_ID, 'v0.'.$parts[1].'.'.$parts[2]));
    }

    public function testRetainedOldKeyVerifiesButActiveVersionIssuesNewTokens(): void
    {
        $oldCodec = new VersionedCsrfTokenCodec('v1', '{"v1":"'.self::V1_KEY.'"}');
        $rotated = new VersionedCsrfTokenCodec('v2', '{"v1":"'.self::V1_KEY.'","v2":"'.self::V2_KEY.'"}');
        $oldToken = $oldCodec->issue(self::SESSION_ID);
        $newToken = $rotated->issue(self::SESSION_ID);

        self::assertTrue($rotated->verify(self::SESSION_ID, $oldToken));
        self::assertStringStartsWith('v2.', $newToken);
    }

    public function testRejectsShortHmacKeyWithoutLeakingSecretMaterial(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            new VersionedCsrfTokenCodec('v1', '{"v1":"c2hvcnQ="}');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('c2hvcnQ=', $exception->getMessage());
            throw $exception;
        }
    }

    private static function changeFirstCharacter(string $value): string
    {
        return ('A' === $value[0] ? 'B' : 'A').substr($value, 1);
    }
}
