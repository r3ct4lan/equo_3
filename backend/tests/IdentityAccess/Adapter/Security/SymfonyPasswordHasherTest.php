<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\SymfonyPasswordHasher;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

final class SymfonyPasswordHasherTest extends KernelTestCase
{
    public function testApplicationPortResolvesToWorkingHasherInTestContainer(): void
    {
        self::bootKernel();

        $port = self::getContainer()->get(PasswordHashingPort::class);
        $plainPassword = 'container resolved password';
        $hash = $port->hash($plainPassword);

        self::assertNotSame($plainPassword, $hash);
    }

    public function testPasswordIsHashedWithConfiguredAutoHasher(): void
    {
        $factory = new PasswordHasherFactory([
            PasswordHashingPort::class => ['algorithm' => 'auto'],
        ]);
        $port = new SymfonyPasswordHasher($factory);

        $plainPassword = 'correct horse battery staple';
        $hash = $port->hash($plainPassword);
        $hasher = $factory->getPasswordHasher(PasswordHashingPort::class);

        self::assertNotSame($plainPassword, $hash);
        self::assertStringNotContainsString($plainPassword, $hash);
        self::assertTrue($hasher->verify($hash, $plainPassword));
        self::assertFalse($hasher->verify($hash, 'incorrect password'));
    }
}
