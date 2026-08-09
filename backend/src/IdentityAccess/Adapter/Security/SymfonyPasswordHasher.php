<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\PasswordHashingPort;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final readonly class SymfonyPasswordHasher implements PasswordHashingPort
{
    private PasswordHasherInterface $hasher;
    private string $dummyHash;

    public function __construct(PasswordHasherFactoryInterface $factory)
    {
        $this->hasher = $factory->getPasswordHasher(PasswordHashingPort::class);
        $this->dummyHash = $this->hasher->hash('dummy-login-password-verification-boundary');
    }

    public function hash(string $plainPassword): string
    {
        return $this->hasher->hash($plainPassword);
    }

    public function verify(string $plainPassword, ?string $passwordHash): bool
    {
        if (null === $passwordHash) {
            $this->hasher->verify($this->dummyHash, $plainPassword);

            return false;
        }

        return $this->hasher->verify($passwordHash, $plainPassword);
    }
}
