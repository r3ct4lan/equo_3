<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api\Error;

use RuntimeException;

final class ApplicationFailure extends RuntimeException
{
    /** @param array<string,mixed>|null $details */
    public function __construct(
        public readonly ApplicationFailureCode $failureCode,
        public readonly ?int $retryAfter = null,
        public readonly ?array $details = null,
    ) {
        parent::__construct($failureCode->value);
    }
}
