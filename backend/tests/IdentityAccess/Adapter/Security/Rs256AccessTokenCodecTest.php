<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\Rs256AccessTokenCodec;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\UuidPort;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\RegisteredClaims;
use PHPUnit\Framework\TestCase;

final class Rs256AccessTokenCodecTest extends TestCase
{
    private const string USER_ID = '952adfb3-c5f6-4bd9-87cb-405d456a2f9a';
    private const string ISSUER = 'https://equo.test';
    private const string AUDIENCE = 'equo-api';

    private DateTimeImmutable $now;

    /** @var array{private: string, public: string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-08T12:00:00Z', new DateTimeZone('UTC'));
        $this->keys = self::rsaKeyPair(2048);
    }

    public function testIssuesJwtWithNormativeHeadersClaimsAndNoPersonalData(): void
    {
        $codec = $this->codec(['jti-1', 'jti-2']);

        $first = $codec->issue(self::USER_ID);
        $second = $codec->issue(self::USER_ID);
        $token = $this->parse($first->accessToken);

        self::assertSame(900, $first->expiresIn);
        self::assertSame('RS256', $token->headers()->get('alg'));
        self::assertSame('at+jwt', $token->headers()->get('typ'));
        self::assertSame('v1', $token->headers()->get('kid'));
        self::assertSame(self::ISSUER, $token->claims()->get(RegisteredClaims::ISSUER));
        self::assertSame([self::AUDIENCE], $token->claims()->get(RegisteredClaims::AUDIENCE));
        self::assertSame(self::USER_ID, $token->claims()->get(RegisteredClaims::SUBJECT));
        self::assertSame('jti-1', $token->claims()->get(RegisteredClaims::ID));
        self::assertSame(900, $token->claims()->get(RegisteredClaims::EXPIRATION_TIME)->getTimestamp() - $token->claims()->get(RegisteredClaims::ISSUED_AT)->getTimestamp());
        self::assertNotSame($this->parse($second->accessToken)->claims()->get(RegisteredClaims::ID), $token->claims()->get(RegisteredClaims::ID));

        $claims = $token->claims()->all();
        foreach (['email', 'name', 'passwordHash', 'refreshToken', 'csrfToken', 'roles'] as $forbiddenClaim) {
            self::assertArrayNotHasKey($forbiddenClaim, $claims);
        }

        self::assertNotNull($codec->verify($first->accessToken));
    }

    public function testVerifiesAValidTokenAndReturnsOnlyMinimalResult(): void
    {
        $codec = $this->codec();
        $verified = $codec->verify($codec->issue(self::USER_ID)->accessToken);

        self::assertNotNull($verified);
        self::assertSame(self::USER_ID, $verified->userId);
        self::assertSame($this->now->getTimestamp(), $verified->issuedAt->getTimestamp());
        self::assertSame($this->now->getTimestamp() + 900, $verified->expiresAt->getTimestamp());
        self::assertSame('jti-1', $verified->jwtId);
    }

    public function testRejectsMalformedWrongSignatureUnknownKidAlgTypIssuerAudienceAndSubject(): void
    {
        $codec = $this->codec();
        $otherKeys = self::rsaKeyPair(2048);

        self::assertNull($codec->verify('not-a-jwt'));
        self::assertNull($codec->verify($this->buildToken(privateKey: $otherKeys['private'])));
        self::assertNull($codec->verify($this->buildToken(headers: ['kid' => 'v2'])));
        self::assertNull($codec->verify($this->buildHmacToken()));
        self::assertNull($codec->verify($this->buildToken(headers: ['typ' => 'JWT'])));
        self::assertNull($codec->verify($this->buildToken(issuer: 'https://issuer.invalid')));
        self::assertNull($codec->verify($this->buildToken(audience: 'other-api')));
        self::assertNull($codec->verify($this->buildToken(subject: 'not-a-uuid')));
    }

    public function testRejectsEveryMissingRequiredClaim(): void
    {
        $codec = $this->codec();

        foreach ([
            RegisteredClaims::ISSUER,
            RegisteredClaims::AUDIENCE,
            RegisteredClaims::SUBJECT,
            RegisteredClaims::ISSUED_AT,
            RegisteredClaims::EXPIRATION_TIME,
            RegisteredClaims::ID,
        ] as $claim) {
            self::assertNull($codec->verify($this->buildToken(omit: [$claim])), $claim.' should be required');
        }
    }

    public function testRejectsExpiredTokenAndFutureIatBeyondConfiguredSkew(): void
    {
        $codec = $this->codec(clockSkewSeconds: 30);

        self::assertNull($codec->verify($this->buildToken(issuedAt: $this->now->modify('-931 seconds'))));
        self::assertNull($codec->verify($this->buildToken(issuedAt: $this->now->modify('+31 seconds'))));
    }

    public function testAcceptsFutureIatWithinConfiguredSkew(): void
    {
        $codec = $this->codec(clockSkewSeconds: 30);

        self::assertNotNull($codec->verify($this->buildToken(issuedAt: $this->now->modify('+30 seconds'))));
    }

    public function testRejectsInvalidConfigurationWithoutLeakingKeyMaterial(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The JWT signing key is invalid.');

        try {
            $this->codec(encodedPrivateKey: 'not-base64-private-key');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('not-base64-private-key', $exception->getMessage());
            throw $exception;
        }
    }

    public function testRejectsClockSkewAboveThirtySecondsAndSmallRsaKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec(clockSkewSeconds: 31);
    }

    public function testRejectsRsaKeysSmallerThan2048Bits(): void
    {
        $smallKeys = self::rsaKeyPair(1024);

        $this->expectException(InvalidArgumentException::class);
        $this->codec(
            encodedPrivateKey: base64_encode($smallKeys['private']),
            encodedPublicKeyRing: json_encode(['v1' => base64_encode($smallKeys['public'])], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param non-empty-list<string> $jti
     */
    private function codec(
        array $jti = ['jti-1'],
        int $clockSkewSeconds = 30,
        ?string $encodedPrivateKey = null,
        ?string $encodedPublicKeyRing = null,
    ): Rs256AccessTokenCodec {
        return new Rs256AccessTokenCodec(
            new FixedClock($this->now),
            new FixedUuid($jti),
            'v1',
            $encodedPrivateKey ?? base64_encode($this->keys['private']),
            $encodedPublicKeyRing ?? json_encode(['v1' => base64_encode($this->keys['public'])], JSON_THROW_ON_ERROR),
            self::ISSUER,
            self::AUDIENCE,
            900,
            $clockSkewSeconds,
        );
    }

    private function parse(string $jwt): \Lcobucci\JWT\UnencryptedToken
    {
        if ('' === $jwt) {
            self::fail('JWT must be non-empty.');
        }
        /** @var non-empty-string $jwt */
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::nonEmpty($this->keys['private'])),
            InMemory::plainText(self::nonEmpty($this->keys['public'])),
        );

        $token = $configuration->parser()->parse($jwt);
        self::assertInstanceOf(\Lcobucci\JWT\UnencryptedToken::class, $token);

        return $token;
    }

    /**
     * @param array<string, string> $headers
     * @param list<string>          $omit
     */
    private function buildToken(
        array $headers = [],
        array $omit = [],
        string $issuer = self::ISSUER,
        string $audience = self::AUDIENCE,
        string $subject = self::USER_ID,
        ?DateTimeImmutable $issuedAt = null,
        ?string $privateKey = null,
    ): string {
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::nonEmpty($privateKey ?? $this->keys['private'])),
            InMemory::plainText(self::nonEmpty($this->keys['public'])),
        );

        $issuedAt ??= $this->now;
        if ('' === $issuer || '' === $audience || '' === $subject) {
            self::fail('JWT test claim values must be non-empty.');
        }
        /** @var non-empty-string $issuer */
        /** @var non-empty-string $audience */
        /** @var non-empty-string $subject */
        $builder = $configuration->builder()
            ->withHeader('typ', $headers['typ'] ?? 'at+jwt')
            ->withHeader('kid', $headers['kid'] ?? 'v1');

        if (!in_array(RegisteredClaims::ISSUER, $omit, true)) {
            $builder = $builder->issuedBy($issuer);
        }
        if (!in_array(RegisteredClaims::AUDIENCE, $omit, true)) {
            $builder = $builder->permittedFor($audience);
        }
        if (!in_array(RegisteredClaims::SUBJECT, $omit, true)) {
            $builder = $builder->relatedTo($subject);
        }
        if (!in_array(RegisteredClaims::ISSUED_AT, $omit, true)) {
            $builder = $builder->issuedAt($issuedAt);
        }
        if (!in_array(RegisteredClaims::EXPIRATION_TIME, $omit, true)) {
            $builder = $builder->expiresAt($issuedAt->modify('+900 seconds'));
        }
        if (!in_array(RegisteredClaims::ID, $omit, true)) {
            $builder = $builder->identifiedBy('jti-custom');
        }

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    private function buildHmacToken(): string
    {
        $configuration = Configuration::forSymmetricSigner(
            new HmacSha256(),
            InMemory::plainText(str_repeat('a', 32)),
        );

        return $configuration->builder()
            ->withHeader('typ', 'at+jwt')
            ->withHeader('kid', 'v1')
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->relatedTo(self::USER_ID)
            ->issuedAt($this->now)
            ->expiresAt($this->now->modify('+900 seconds'))
            ->identifiedBy('jti-hmac')
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /** @return array{private: string, public: string} */
    private static function rsaKeyPair(int $bits): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);

        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return ['private' => $privatePem, 'public' => $details['key']];
    }

    /** @return non-empty-string */
    private static function nonEmpty(string $value): string
    {
        if ('' === $value) {
            self::fail('Expected a non-empty string.');
        }

        return $value;
    }
}

final readonly class FixedClock implements ClockPort
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class FixedUuid implements UuidPort
{
    private int $index = 0;

    /** @param non-empty-list<string> $ids */
    public function __construct(private readonly array $ids)
    {
    }

    public function generate(): string
    {
        return $this->ids[$this->index++] ?? 'jti-fallback';
    }
}
