<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Application\Login\LoginUserView;
use App\IdentityAccess\Domain\User\User;

interface TelegramIdentityRepositoryPort
{
    /** Serialize all mutations of this Telegram ID in the active transaction. */
    public function lockIdentity(int $telegramId): void;

    public function accountForUpdate(int $telegramId): ?LoginUserView;

    public function add(User $user): void;
}
