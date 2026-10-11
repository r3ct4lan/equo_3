<?php

declare(strict_types=1);

namespace App\Tests\Invitations\Adapter\Security;

use App\Invitations\Adapter\Security\InvitationTokenCodec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InvitationTokenCodecTest extends TestCase
{
    private const string ID = '00000000-0000-4000-8000-000000000001';

    private function codec(string $version = 'old', bool $old = true): InvitationTokenCodec
    {
        $keys = ['new' => base64_encode(str_repeat('b', 32))];
        if ($old) {
            $keys['old'] = base64_encode(str_repeat('a', 32));
        }

        return new InvitationTokenCodec($version, json_encode($keys, JSON_THROW_ON_ERROR));
    }

    public function testSignatureHashAndRotationPreserveOriginalToken(): void
    {
        $old = $this->codec()->issue(self::ID);
        $rotated = $this->codec('new');
        self::assertSame(self::ID, $rotated->verify($old));
        self::assertSame($old, $rotated->reproduce(self::ID, $rotated->digest($old)));
        self::assertNotSame($old, $rotated->issue(self::ID));
        self::assertNull($rotated->verify(substr($old, 0, -1).'!'));
        self::assertNull($rotated->verify($old.'='));
        self::assertNull($rotated->verify(strtoupper($old)));
        self::assertNull($this->codec('new', false)->verify($old));
    }

    public function testMissingCompatibilityKeyFailsClosed(): void
    {
        $old = $this->codec()->issue(self::ID);
        $this->expectException(RuntimeException::class);
        $this->codec('new', false)->reproduce(self::ID, hash('sha256', $old));
    }

    public function testShortKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvitationTokenCodec('v1', '{"v1":"YQ=="}');
    }
}
