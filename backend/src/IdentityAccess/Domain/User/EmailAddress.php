<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\User;

use InvalidArgumentException;

final readonly class EmailAddress
{
    public string $value;

    public function __construct(string $email)
    {
        $normalized = mb_strtolower(trim($email), 'UTF-8');

        if (false === filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address is invalid.');
        }

        $this->value = $normalized;
    }
}
