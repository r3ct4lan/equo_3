<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

final readonly class LoginUserView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public bool $isActive,
        public string $locale = 'en',
        public string $currency = 'EUR',
    ) {
    }

    /** @return array{id: string, name: string, email: ?string, isActive: bool, locale: string, currency:string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'isActive' => $this->isActive,
            'locale' => $this->locale,
            'currency' => $this->currency,
        ];
    }
}
