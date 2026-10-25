<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Domain\ManualTransferFailure;
use App\Transfers\Domain\ManualTransferRejected;

final class ManualTransferFailureMapper
{
    public static function map(ManualTransferRejected $error, int $expectedVersion, int $actualVersion): ApplicationFailure
    {
        $code = match ($error->reason) {
            ManualTransferFailure::Automatic => ApplicationFailureCode::AutomaticTransferImmutable,
            ManualTransferFailure::Forbidden => ApplicationFailureCode::Forbidden,
            ManualTransferFailure::Deleted => ApplicationFailureCode::TransferDeleted,
            ManualTransferFailure::Frozen => ApplicationFailureCode::TransferFrozen,
            ManualTransferFailure::VersionConflict => ApplicationFailureCode::VersionConflict,
            ManualTransferFailure::InvalidSides => ApplicationFailureCode::ValidationError,
        };

        return new ApplicationFailure($code, details: ManualTransferFailure::VersionConflict === $error->reason ? ['expectedVersion' => $expectedVersion, 'actualVersion' => $actualVersion] : null);
    }
}
