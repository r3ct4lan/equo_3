<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Domain\User\User;
use App\IdentityAccess\Domain\User\VerifiedTelegramIdentity;
use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TelegramOidcClient
{
    private const string ISSUER = 'https://oauth.telegram.org';
    private const string CALLBACK = '/api/v1/auth/telegram/oidc/callback';

    public function __construct(
        private HttpClientInterface $http,
        private ClockPort $clock,
        #[Autowire(env: 'EQUO_TELEGRAM_CLIENT_ID')]
        private string $clientId,
        #[Autowire(env: 'EQUO_TELEGRAM_CLIENT_SECRET')]
        #[SensitiveParameter]
        private string $clientSecret,
        #[Autowire(env: 'EQUO_APPLICATION_ORIGIN')]
        private string $origin,
    ) {
    }

    public function authorizationUrl(string $state, #[SensitiveParameter] string $verifier): string
    {
        $this->requireConfiguration();

        return self::ISSUER.'/auth?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->origin.self::CALLBACK,
            'response_type' => 'code',
            'scope' => 'openid profile',
            'state' => $state,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public function exchange(#[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): VerifiedTelegramIdentity
    {
        $this->requireConfiguration();
        $options = ['max_redirects' => 0, 'timeout' => 5.0, 'max_duration' => 10.0, 'extra' => ['trace_content' => false]];
        try {
            $tokens = $this->http->request('POST', self::ISSUER.'/token', $options + [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->origin.self::CALLBACK,
                    'client_id' => $this->clientId,
                    'code_verifier' => $verifier,
                ],
            ])->toArray();
            $idToken = $tokens['id_token'] ?? null;
            if (!is_string($idToken) || '' === $idToken || strlen($idToken) > 16384) {
                throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
            }
            $jwks = $this->http->request('GET', self::ISSUER.'/.well-known/jwks.json', $options)->toArray();
            $keys = array_filter(is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [], static fn (mixed $key): bool => is_array($key)
                && 'RSA' === ($key['kty'] ?? null)
                && 'RS256' === ($key['alg'] ?? 'RS256')
                && 'sig' === ($key['use'] ?? 'sig'));
            $claims = JWT::decode($idToken, JWK::parseKeySet(['keys' => $keys], 'RS256'));
        } catch (Exception) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
        }

        $audiences = is_string($claims->aud ?? null) ? [$claims->aud] : ($claims->aud ?? null);
        $now = $this->clock->now()->getTimestamp();
        $id = $claims->id ?? null;
        $expiresAt = $claims->exp ?? null;
        $issuedAt = $claims->iat ?? null;
        if (self::ISSUER !== ($claims->iss ?? null) || !is_string($claims->sub ?? null) || '' === $claims->sub
            || !is_array($audiences) || !in_array($this->clientId, $audiences, true)
            || (count($audiences) > 1 && $this->clientId !== ($claims->azp ?? null))
            || (!is_int($expiresAt) && !is_float($expiresAt)) || !is_finite((float) $expiresAt) || $expiresAt <= $now
            || (!is_int($issuedAt) && !is_float($issuedAt)) || !is_finite((float) $issuedAt) || $issuedAt > $now + 30 || $issuedAt >= $expiresAt
            || !is_int($id) || $id < 1 || $id > User::MAX_TELEGRAM_ID
        ) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
        }

        $name = is_string($claims->name ?? null) ? trim(str_replace(["\r", "\n", "\0"], ' ', $claims->name)) : '';

        return new VerifiedTelegramIdentity($id, '' === $name ? 'Telegram user' : mb_substr($name, 0, 100));
    }

    public static function randomUrlToken(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function requireConfiguration(): void
    {
        if (1 !== preg_match('/\A[1-9][0-9]{0,15}\z/D', $this->clientId) || '' === $this->clientSecret || !TelegramDeploymentConfiguration::isHttpsOrigin($this->origin)) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }
    }
}
