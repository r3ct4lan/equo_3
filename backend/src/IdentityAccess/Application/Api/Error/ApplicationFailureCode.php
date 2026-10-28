<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api\Error;

enum ApplicationFailureCode: string
{
    case InvalidRequest = 'INVALID_REQUEST';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case EmailAlreadyExists = 'EMAIL_ALREADY_EXISTS';
    case EmailAlreadyInUse = 'EMAIL_ALREADY_IN_USE';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case PasswordPolicyViolation = 'PASSWORD_POLICY_VIOLATION';
    case PasswordUnchanged = 'PASSWORD_UNCHANGED';
    case InvalidToken = 'INVALID_TOKEN';
    case TokenExpired = 'TOKEN_EXPIRED';
    case TokenUsed = 'TOKEN_USED';
    case TokenInvalidated = 'TOKEN_INVALIDATED';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountInactive = 'ACCOUNT_INACTIVE';
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case InvalidRefreshToken = 'INVALID_REFRESH_TOKEN';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case InactiveUser = 'INACTIVE_USER';
    case ValidationError = 'VALIDATION_ERROR';
    case InvalidParticipant = 'INVALID_PARTICIPANT';
    case DebtNotAvailable = 'DEBT_NOT_AVAILABLE';
    case TransferNotAvailable = 'TRANSFER_NOT_AVAILABLE';
    case DebtFrozen = 'DEBT_FROZEN';
    case DebtDeleted = 'DEBT_DELETED';
    case AutomaticTransferImmutable = 'AUTOMATIC_TRANSFER_IMMUTABLE';
    case TransferFrozen = 'TRANSFER_FROZEN';
    case TransferDeleted = 'TRANSFER_DELETED';
    case VersionConflict = 'VERSION_CONFLICT';
    case PreconditionRequired = 'PRECONDITION_REQUIRED';
    case Forbidden = 'FORBIDDEN';
}
