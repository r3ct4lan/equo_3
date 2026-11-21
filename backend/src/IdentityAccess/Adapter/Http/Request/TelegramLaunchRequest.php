<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

final readonly class TelegramLaunchRequest
{
    public function __construct(public ?string $initData = null)
    {
    }
}
