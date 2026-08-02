<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\EmailDelivery\Security;

use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use PHPUnit\Framework\TestCase;

final class PayloadCipherTest extends TestCase
{
    public function testPayloadRoundTripDoesNotExposePlaintext(): void
    {
        $cipher = new PayloadCipher('ZW1haWwtcGF5bG9hZC1kZXYta2V5LTMyLWJ5dGVzISE=');
        $encrypted = $cipher->encrypt(['token' => 'v1.public-secret']);

        self::assertStringNotContainsString('public-secret', $encrypted);
        self::assertSame(['token' => 'v1.public-secret'], $cipher->decrypt($encrypted));
    }
}
