<?php

declare(strict_types=1);

namespace App\Connects\Application;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class CreateConnectCommand
{
    public string $otherUserId;
    public string $sharedDebtId;
    public string $idempotencyKey;

    public function __construct(string $otherUserId, string $sharedDebtId, string $idempotencyKey)
    {
        foreach ([$otherUserId, $sharedDebtId, $idempotencyKey] as $id) {
            if (!self::isUuid($id)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        $this->otherUserId = strtolower($otherUserId);
        $this->sharedDebtId = strtolower($sharedDebtId);
        $this->idempotencyKey = strtolower($idempotencyKey);
    }

    public static function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
    }

    public function canonical(): string
    {
        return json_encode(['otherUserId' => $this->otherUserId, 'sharedDebtId' => $this->sharedDebtId], JSON_THROW_ON_ERROR);
    }
}
