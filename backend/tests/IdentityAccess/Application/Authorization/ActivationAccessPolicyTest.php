<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\Authorization;

use App\IdentityAccess\Application\Authorization\ActivationAccessDenial;
use App\IdentityAccess\Application\Authorization\ActivationAccessPolicy;
use App\IdentityAccess\Application\Authorization\ActivationTokenAccess;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ActivationAccessPolicyTest extends TestCase
{
    private const string USER_ID = '550e8400-e29b-41d4-a716-446655440000';

    private ActivationAccessPolicy $policy;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->policy = new ActivationAccessPolicy();
        $this->now = new DateTimeImmutable('2026-07-31T16:00:00Z');
    }

    public function testValidActivationTokenAuthorizesOnlyItsUser(): void
    {
        $decision = $this->policy->decide($this->token(), $this->now);

        self::assertTrue($decision->isAllowed());
        self::assertNull($decision->denial);
        self::assertSame(self::USER_ID, $decision->userId());
    }

    public function testUnknownTokenIsDeniedWithoutAUserReference(): void
    {
        $decision = $this->policy->decide(null, $this->now);

        self::assertFalse($decision->isAllowed());
        self::assertSame(ActivationAccessDenial::InvalidToken, $decision->denial);
    }

    public function testTokenForAnotherPurposeCannotActivateAnAccount(): void
    {
        $decision = $this->policy->decide($this->token(
            purpose: UserActionTokenPurpose::ResetPassword,
        ), $this->now);

        self::assertFalse($decision->isAllowed());
        self::assertSame(ActivationAccessDenial::InvalidToken, $decision->denial);
    }

    public function testTokenExpiresAtTheExactExpiryBoundary(): void
    {
        $decision = $this->policy->decide($this->token(expiresAt: $this->now), $this->now);

        self::assertFalse($decision->isAllowed());
        self::assertSame(ActivationAccessDenial::ExpiredToken, $decision->denial);
    }

    public function testUsedTokenIsDenied(): void
    {
        $decision = $this->policy->decide($this->token(usedAt: $this->now->modify('-1 minute')), $this->now);

        self::assertFalse($decision->isAllowed());
        self::assertSame(ActivationAccessDenial::UsedToken, $decision->denial);
    }

    public function testInvalidatedTokenIsDenied(): void
    {
        $decision = $this->policy->decide($this->token(
            invalidatedAt: $this->now->modify('-1 minute'),
        ), $this->now);

        self::assertFalse($decision->isAllowed());
        self::assertSame(ActivationAccessDenial::InvalidatedToken, $decision->denial);
    }

    private function token(
        UserActionTokenPurpose $purpose = UserActionTokenPurpose::ActivateAccount,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $usedAt = null,
        ?DateTimeImmutable $invalidatedAt = null,
    ): ActivationTokenAccess {
        return new ActivationTokenAccess(
            self::USER_ID,
            $purpose,
            $expiresAt ?? $this->now->modify('+1 hour'),
            $usedAt,
            $invalidatedAt,
        );
    }
}
