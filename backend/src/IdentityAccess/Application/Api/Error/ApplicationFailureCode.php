<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api\Error;

enum ApplicationFailureCode: string
{
    case InvalidRequest = 'INVALID_REQUEST';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case EmailAlreadyExists = 'EMAIL_ALREADY_EXISTS';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case PasswordPolicyViolation = 'PASSWORD_POLICY_VIOLATION';
    case InvalidToken = 'INVALID_TOKEN';
    case TokenExpired = 'TOKEN_EXPIRED';
    case TokenUsed = 'TOKEN_USED';
    case TokenInvalidated = 'TOKEN_INVALIDATED';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountInactive = 'ACCOUNT_INACTIVE';
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case InvalidRefreshToken = 'INVALID_REFRESH_TOKEN';
    case Forbidden = 'FORBIDDEN';
}
