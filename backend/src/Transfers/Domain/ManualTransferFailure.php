<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

enum ManualTransferFailure
{
    case Automatic;
    case Forbidden;
    case Deleted;
    case Frozen;
    case VersionConflict;
    case InvalidSides;
}
