<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\AccessTokenVerifierPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\IssuedAccessToken;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Application\Port\VerifiedAccessToken;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class Rs256AccessTokenCodec implements AccessTokenIssuerPort, AccessTokenVerifierPort
{
    private const string KEY_VERSION_PATTERN = '/\A[A-Za-z0-9_-]{1,16}\z/';

    private Sha256 $signer;

    private Configuration $configuration;

    private InMemory $signingKey;

    /** @var array<string, InMemory> */
    private array $publicKeys;

    public function __construct(
        private ClockPort $clock,
        private UuidPort $uuid,
        private string $activeKeyVersion,
        string $encodedSigningPrivateKey,
        string $encodedPublicKeyRing,
        private string $issuer,
        private string $audience,
        private int $accessTtlSeconds,
        private int $clockSkewSeconds,
    ) {
        if (1 !== preg_match(self::KEY_VERSION_PATTERN, $activeKeyVersion)) {
            throw new InvalidArgumentException('The JWT active key version is invalid.');
        }

        if ('' === $issuer || '' === $audience) {
            throw new InvalidArgumentException('The JWT issuer or audience is invalid.');
        }

        if (900 !== $accessTtlSeconds) {
            throw new InvalidArgumentException('The JWT access token TTL is invalid.');
        }

        if ($clockSkewSeconds < 0 || $clockSkewSeconds > 30) {
            throw new InvalidArgumentException('The JWT clock skew is invalid.');
        }

        $privatePem = $this->decodePem($encodedSigningPrivateKey, 'The JWT signing key is invalid.');
        $this->assertRsaKey($privatePem, true, 'The JWT signing key is invalid.');

        $publicKeys = $this->decodePublicKeyRing($encodedPublicKeyRing);
        if (!isset($publicKeys[$activeKeyVersion])) {
            throw new InvalidArgumentException('The JWT active key is missing from the public key ring.');
        }

        $this->signer = new Sha256();
        $this->signingKey = InMemory::plainText($privatePem);
        $this->publicKeys = $publicKeys;
        $this->configuration = Configuration::forAsymmetricSigner(
            $this->signer,
            $this->signingKey,
            $publicKeys[$activeKeyVersion],
        );

        $probe = $this->signer->sign('equo-jwt-key-check', $this->signingKey);
        if (!$this->signer->verify($probe, 'equo-jwt-key-check', $publicKeys[$activeKeyVersion])) {
            throw new InvalidArgumentException('The JWT signing key does not match the active public key.');
        }
    }

    public function issue(string $userId): IssuedAccessToken
    {
        if (!Uuid::isValid($userId)) {
            throw new InvalidArgumentException('The JWT subject is invalid.');
        }
        /** @var non-empty-string $userId */
        $jwtId = $this->uuid->generate();
        if ('' === $jwtId) {
            throw new LogicException('The JWT id generator returned an invalid value.');
        }
        /** @var non-empty-string $jwtId */
        $issuer = $this->issuer;
        $audience = $this->audience;
        if ('' === $issuer || '' === $audience) {
            throw new LogicException('The JWT issuer or audience is invalid.');
        }
        /** @var non-empty-string $issuer */
        /** @var non-empty-string $audience */
        $issuedAt = $this->clock->now();
        $expiresAt = $issuedAt->modify('+'.$this->accessTtlSeconds.' seconds');

        $token = $this->configuration->builder()
            ->withHeader('typ', 'at+jwt')
            ->withHeader('kid', $this->activeKeyVersion)
            ->issuedBy($issuer)
            ->permittedFor($audience)
            ->relatedTo($userId)
            ->issuedAt($issuedAt)
            ->expiresAt($expiresAt)
            ->identifiedBy($jwtId)
            ->getToken($this->signer, $this->signingKey);

        return new IssuedAccessToken($token->toString(), $this->accessTtlSeconds);
    }

    public function verify(string $accessToken): ?VerifiedAccessToken
    {
        if ('' === $accessToken) {
            return null;
        }
        /* @var non-empty-string $accessToken */

        try {
            $token = $this->configuration->parser()->parse($accessToken);
        } catch (Throwable) {
            return null;
        }

        if (!$token instanceof UnencryptedToken) {
            return null;
        }

        $headers = $token->headers();
        $kid = $headers->get('kid');
        if (
            'RS256' !== $headers->get('alg')
            || 'at+jwt' !== $headers->get('typ')
            || !is_string($kid)
            || !isset($this->publicKeys[$kid])
        ) {
            return null;
        }

        try {
            if (!$this->signer->verify($token->signature()->hash(), $token->payload(), $this->publicKeys[$kid])) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $claims = $token->claims();
        foreach ([RegisteredClaims::ISSUER, RegisteredClaims::AUDIENCE, RegisteredClaims::SUBJECT, RegisteredClaims::ISSUED_AT, RegisteredClaims::EXPIRATION_TIME, RegisteredClaims::ID] as $claim) {
            if (!$claims->has($claim)) {
                return null;
            }
        }

        $subject = $claims->get(RegisteredClaims::SUBJECT);
        $jwtId = $claims->get(RegisteredClaims::ID);
        $issuedAt = $claims->get(RegisteredClaims::ISSUED_AT);
        $expiresAt = $claims->get(RegisteredClaims::EXPIRATION_TIME);
        $audience = $claims->get(RegisteredClaims::AUDIENCE);

        if (
            $claims->get(RegisteredClaims::ISSUER) !== $this->issuer
            || !is_array($audience)
            || [$this->audience] !== array_values($audience)
            || !is_string($subject)
            || !Uuid::isValid($subject)
            || !is_string($jwtId)
            || '' === $jwtId
            || !$issuedAt instanceof DateTimeImmutable
            || !$expiresAt instanceof DateTimeImmutable
            || ($expiresAt->getTimestamp() - $issuedAt->getTimestamp()) !== $this->accessTtlSeconds
        ) {
            return null;
        }

        $now = $this->clock->now();
        if ($issuedAt->getTimestamp() > $now->getTimestamp() + $this->clockSkewSeconds) {
            return null;
        }

        if ($expiresAt->getTimestamp() <= $now->getTimestamp() - $this->clockSkewSeconds) {
            return null;
        }

        return new VerifiedAccessToken($subject, $issuedAt, $expiresAt, $jwtId);
    }

    /**
     * @return array<string, InMemory>
     *
     * @throws JsonException
     */
    private function decodePublicKeyRing(string $encodedPublicKeyRing): array
    {
        $decoded = json_decode($encodedPublicKeyRing, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('The JWT public key ring is invalid.');
        }

        $keys = [];
        foreach ($decoded as $version => $encodedPem) {
            if (!is_string($version) || 1 !== preg_match(self::KEY_VERSION_PATTERN, $version) || !is_string($encodedPem)) {
                throw new InvalidArgumentException('The JWT public key ring contains an invalid entry.');
            }

            $pem = $this->decodePem($encodedPem, 'The JWT public key ring contains an invalid entry.');
            $this->assertRsaKey($pem, false, 'The JWT public key ring contains an invalid entry.');
            $keys[$version] = InMemory::plainText($pem);
        }

        return $keys;
    }

    /** @return non-empty-string */
    private function decodePem(string $encodedPem, string $message): string
    {
        $pem = base64_decode($encodedPem, true);
        if (false === $pem || '' === $pem) {
            throw new InvalidArgumentException($message);
        }

        return $pem;
    }

    private function assertRsaKey(string $pem, bool $private, string $message): void
    {
        $key = $private ? @openssl_pkey_get_private($pem) : @openssl_pkey_get_public($pem);
        if (false === $key) {
            throw new InvalidArgumentException($message);
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== \OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            throw new InvalidArgumentException($message);
        }
    }
}
