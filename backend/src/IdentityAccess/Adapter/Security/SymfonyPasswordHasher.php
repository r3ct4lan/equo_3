<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\PasswordHashingPort;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final readonly class SymfonyPasswordHasher implements PasswordHashingPort
{
    private PasswordHasherInterface $hasher;

    public function __construct(PasswordHasherFactoryInterface $factory)
    {
        $this->hasher = $factory->getPasswordHasher(PasswordHashingPort::class);
    }

    public function hash(string $plainPassword): string
    {
        return $this->hasher->hash($plainPassword);
    }
}
