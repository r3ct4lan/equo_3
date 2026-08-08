<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\RefreshTokenCodec;
use PHPUnit\Framework\TestCase;

final class RefreshTokenCodecTest extends TestCase
{
    public function testIssuesAtLeast256BitOpaqueTokenAndStableDigest(): void
    {
        $codec = new RefreshTokenCodec();
        $issued = $codec->issue();

        self::assertMatchesRegularExpression('/\Art\.[A-Za-z0-9_-]{43}\z/', $issued->publicToken);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/', $issued->tokenHash);
        self::assertSame($issued->tokenHash, $codec->digest($issued->publicToken));
        self::assertNotSame($issued->publicToken, $issued->tokenHash);
    }

    public function testDifferentTokensProduceDifferentDigests(): void
    {
        $codec = new RefreshTokenCodec();

        self::assertNotSame($codec->issue()->tokenHash, $codec->issue()->tokenHash);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $codec = new RefreshTokenCodec();

        self::assertNull($codec->digest('malformed'));
        self::assertNull($codec->digest('rt.'.str_repeat('A', 42)));
        self::assertNull($codec->digest('v1.'.str_repeat('A', 43)));
    }
}
