<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class UpdateManualTransferCommand
{
    public string $transferId;
    public string $senderId;
    public string $receiverId;

    public function __construct(string $transferId, string $senderId, string $receiverId, public int $amount, public int $expectedVersion, public bool $relatedSpecified = false, public ?string $relatedTransferId = null)
    {
        foreach ([$transferId, $senderId, $receiverId] as $id) {
            if (!CreateManualTransferCommand::isUuid($id)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        if ($amount < 1 || $amount > 2147483647 || strtolower($senderId) === strtolower($receiverId) || (null !== $relatedTransferId && !CreateManualTransferCommand::isUuid($relatedTransferId))) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        $this->transferId = strtolower($transferId);
        $this->senderId = strtolower($senderId);
        $this->receiverId = strtolower($receiverId);
    }
}
