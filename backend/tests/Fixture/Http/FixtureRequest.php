<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Http;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class FixtureRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        public ?string $name = null,
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        #[Assert\Uuid(message: 'Value must be a UUID.', payload: ['code' => 'INVALID_UUID'])]
        public ?string $id = null,
    ) {
    }
}
