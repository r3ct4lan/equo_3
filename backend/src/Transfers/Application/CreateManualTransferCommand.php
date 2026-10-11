<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class CreateManualTransferCommand
{
    public string $connectId;
    public string $senderId;
    public string $receiverId;
    public string $idempotencyKey;

    public function __construct(string $connectId, string $senderId, string $receiverId, public int $amount, string $idempotencyKey)
    {
        foreach ([$connectId, $senderId, $receiverId, $idempotencyKey] as $id) {
            if (!self::isUuid($id)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        if ($amount < 1 || $amount > 2147483647 || strtolower($senderId) === strtolower($receiverId)) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        $this->connectId = strtolower($connectId);
        $this->senderId = strtolower($senderId);
        $this->receiverId = strtolower($receiverId);
        $this->idempotencyKey = strtolower($idempotencyKey);
    }

    public static function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
    }

    public function canonical(): string
    {
        return json_encode(['connectId' => $this->connectId, 'senderId' => $this->senderId, 'receiverId' => $this->receiverId, 'amount' => $this->amount], JSON_THROW_ON_ERROR);
    }
}
