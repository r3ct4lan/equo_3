<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Api\UserView;
use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AuthenticatedUser implements UserInterface
{
    public function __construct(private UserView $profile)
    {
    }

    public function profile(): UserView
    {
        return $this->profile;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->profile->id) {
            throw new LogicException('Authenticated user identifier is invalid.');
        }
        /** @var non-empty-string $id */
        $id = $this->profile->id;

        return $id;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }
}
