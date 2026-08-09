<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use App\IdentityAccess\Adapter\Security\BearerAccessTokenAuthenticator;
use App\IdentityAccess\Application\Api\TokenDeliveryState;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\CurrentUser\GetCurrentUser;
use App\IdentityAccess\Application\Port\AccessTokenVerifierPort;
use App\IdentityAccess\Application\Port\CurrentUserState;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\StoredActivationToken;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Application\Port\VerifiedAccessToken;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\User\User;
use App\Infrastructure\Http\ApiJsonResponder;
use App\Infrastructure\Http\BearerAuthenticationEntryPoint;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;

final class BearerAccessTokenAuthenticatorTest extends TestCase
{
    private const string USER_ID = '87000000-0000-4000-8000-000000000101';

    public function testValidBearerTokenCreatesPrincipalWithUuidAndPublicProfile(): void
    {
        $authenticator = $this->authenticator(['valid-token' => self::USER_ID], $this->profile(self::USER_ID));
        $passport = $authenticator->authenticate($this->request('Bearer valid-token'));
        $user = $passport->getUser();

        self::assertInstanceOf(AuthenticatedUser::class, $user);
        self::assertSame(self::USER_ID, $user->getUserIdentifier());
        self::assertSame([], $user->getRoles());
        self::assertSame('current@example.test', $user->profile()->email);
        self::assertStringNotContainsString('valid-token', serialize($user));
        self::assertStringNotContainsString('password', serialize($user));
    }

    #[DataProvider('invalidHeaderProvider')]
    public function testInvalidHeadersUseSafeAuthenticationFailure(?string $header): void
    {
        $authenticator = $this->authenticator(['valid-token' => self::USER_ID], $this->profile(self::USER_ID));

        try {
            $authenticator->authenticate($this->request($header));
            self::fail('Invalid Authorization header must fail.');
        } catch (AuthenticationException $exception) {
            self::assertSame('Authentication is required.', $exception->getMessage());
            self::assertStringNotContainsString('valid-token', $exception->getMessage());
            self::assertStringNotContainsString('Bearer', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidHeaderProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong scheme' => ['Basic abc'];
        yield 'empty bearer' => ['Bearer'];
        yield 'blank bearer' => ['Bearer '];
        yield 'multiple values' => ['Bearer one, Bearer two'];
        yield 'extra parts' => ['Bearer one two'];
        yield 'lowercase scheme' => ['bearer token'];
    }

    public function testVerifierNullUnknownUserAndInactiveUserFailSafely(): void
    {
        foreach ([
            'verifier-null' => $this->authenticator([], $this->profile(self::USER_ID)),
            'unknown-user' => $this->authenticator(['valid-token' => self::USER_ID], null),
            'inactive-user' => $this->authenticator(['valid-token' => self::USER_ID], $this->profile(self::USER_ID, false)),
        ] as $authenticator) {
            try {
                $authenticator->authenticate($this->request('Bearer valid-token'));
                self::fail('Authentication must fail.');
            } catch (AuthenticationException $exception) {
                self::assertSame('Authentication is required.', $exception->getMessage());
                self::assertStringNotContainsString('valid-token', $exception->getMessage());
            }
        }
    }

    public function testSupportsOnlyMeRoute(): void
    {
        $authenticator = $this->authenticator(['valid-token' => self::USER_ID], $this->profile(self::USER_ID));

        self::assertTrue($authenticator->supports(Request::create('/api/v1/me')));
        self::assertFalse($authenticator->supports(Request::create('/api/v1/auth/login')));
    }

    /** @param array<string, string> $tokens */
    private function authenticator(array $tokens, ?UserView $profile): BearerAccessTokenAuthenticator
    {
        return new BearerAccessTokenAuthenticator(
            new TokenVerifier($tokens),
            new GetCurrentUser(new AuthenticatorRepository($profile)),
            new BearerAuthenticationEntryPoint(new ApiJsonResponder(new Serializer([new DateTimeNormalizer()], [new JsonEncoder()]))),
        );
    }

    private function request(?string $authorization): Request
    {
        $server = [];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        return Request::create('/api/v1/me', server: $server);
    }

    private function profile(string $userId, bool $active = true): UserView
    {
        return new UserView(
            $userId,
            'Current User',
            'current@example.test',
            $active,
            new DateTimeImmutable('2026-08-08T12:00:00Z'),
        );
    }
}

final readonly class TokenVerifier implements AccessTokenVerifierPort
{
    /** @param array<string, string> $tokens */
    public function __construct(private array $tokens)
    {
    }

    public function verify(string $accessToken): ?VerifiedAccessToken
    {
        if (!isset($this->tokens[$accessToken])) {
            return null;
        }

        return new VerifiedAccessToken(
            $this->tokens[$accessToken],
            new DateTimeImmutable('2026-08-08T12:00:00Z'),
            new DateTimeImmutable('2026-08-08T12:15:00Z'),
            'jti-test',
        );
    }
}

final readonly class AuthenticatorRepository implements IdentityRepositoryPort
{
    public function __construct(private ?UserView $profile)
    {
    }

    public function currentUserProfile(string $userId): ?UserView
    {
        return $this->profile;
    }

    public function emailExists(string $normalizedEmail): bool
    {
        throw new LogicException('Authenticator must not use email lookup.');
    }

    public function loginIdentityByEmail(string $normalizedEmail): ?StoredLoginIdentity
    {
        throw new LogicException('Authenticator must not query password hashes.');
    }

    public function currentUserState(string $userId): ?CurrentUserState
    {
        throw new LogicException('Authenticator must load a public profile.');
    }

    public function addRegistration(User $user, UserActionToken $token): void
    {
        throw new LogicException('Authenticator is read-only.');
    }

    public function activationTokenForUpdate(string $tokenHash): ?StoredActivationToken
    {
        throw new LogicException('Authenticator must not inspect action tokens.');
    }

    public function activate(string $tokenId, string $userId, DateTimeImmutable $usedAt): void
    {
        throw new LogicException('Authenticator is read-only.');
    }

    public function tokenDeliveryState(string $tokenId): ?TokenDeliveryState
    {
        throw new LogicException('Authenticator must not inspect action tokens.');
    }
}
