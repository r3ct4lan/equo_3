<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\VersionedActionTokenCodec;
use PHPUnit\Framework\TestCase;

final class VersionedActionTokenCodecTest extends TestCase
{
    private const string KEY_RING = '{"v1":"YWN0aW9uLXRva2VuLWRldi1rZXktMzItYnl0ZXMhISE="}';

    public function testIssuedTokenUsesVersioned256BitProfileAndStableHmacDigest(): void
    {
        $codec = new VersionedActionTokenCodec('v1', self::KEY_RING);
        $issued = $codec->issue();

        self::assertMatchesRegularExpression('/\Av1\.[A-Za-z0-9_-]{43}\z/', $issued->publicToken);
        self::assertMatchesRegularExpression('/\Av1:[A-Za-z0-9_-]{43}\z/', $issued->tokenHash);
        self::assertSame($issued->tokenHash, $codec->digest($issued->publicToken));
        self::assertNull($codec->digest('v2.'.str_repeat('A', 43)));
        self::assertNull($codec->digest('malformed'));
    }

    public function testDifferentTokensProduceDifferentDigests(): void
    {
        $codec = new VersionedActionTokenCodec('v1', self::KEY_RING);

        self::assertNotSame($codec->issue()->tokenHash, $codec->issue()->tokenHash);
    }
}
