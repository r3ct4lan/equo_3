<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Security\TelegramOidcClient;
use App\IdentityAccess\Adapter\Security\TelegramOidcStateStore;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Login\SignInWithTelegram;
use App\Infrastructure\Application\Api\RequestLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TelegramOidcController
{
    private const string COOKIE = '__Secure-equo_telegram_oidc';
    private const string PATH = '/api/v1/auth/telegram/oidc';
    private const array HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private TelegramOidcClient $provider,
        private TelegramOidcStateStore $attempts,
        private SignInWithTelegram $signIn,
        private AuthCookieFactory $cookies,
        private RequestLimiter $limiter,
    ) {
    }

    #[Route(self::PATH.'/start', name: 'api_v1_auth_telegram_oidc_start', methods: ['GET'])]
    public function start(Request $request): RedirectResponse
    {
        try {
            $retry = $this->limiter->consume(['telegram-oidc:ip:'.($request->getClientIp() ?? 'unknown') => 30]);
            if (null !== $retry) {
                return $this->failure('failed');
            }

            $state = TelegramOidcClient::randomUrlToken();
            $browser = TelegramOidcClient::randomUrlToken();
            $verifier = TelegramOidcClient::randomUrlToken();
            $url = $this->provider->authorizationUrl($state, $verifier);
            $this->attempts->put($state, $browser, $verifier);
        } catch (ApplicationFailure) {
            return $this->failure('failed');
        }

        $response = new RedirectResponse($url, Response::HTTP_SEE_OTHER, self::HEADERS);
        $response->headers->setCookie($this->stateCookie($browser, time() + TelegramOidcStateStore::TTL));

        return $response;
    }

    #[Route(self::PATH.'/callback', name: 'api_v1_auth_telegram_oidc_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $query = $request->query->all();
        $state = $query['state'] ?? null;
        if (!is_string($state)) {
            return $this->failure('failed');
        }

        try {
            $verifier = $this->attempts->consume($state, $request->cookies->getString(self::COOKIE));
            if (null === $verifier) {
                return $this->failure('failed');
            }
            if (isset($query['error'])) {
                return $this->failure('access_denied' === $query['error'] ? 'cancelled' : 'failed');
            }
            $code = $query['code'] ?? null;
            if (!is_string($code) || '' === $code || strlen($code) > 2048) {
                return $this->failure('failed');
            }
            $result = $this->signIn->handle($this->provider->exchange($code, $verifier));
        } catch (ApplicationFailure $failure) {
            return $this->failure(ApplicationFailureCode::AccountInactive === $failure->failureCode ? 'inactive' : 'failed');
        }

        $response = $this->redirect('/');
        $this->cookies->addLoginCookies($response, $result);
        $response->headers->setCookie(Cookie::create('__Host-equo_telegram_signed_in', '1', time() + 60, '/', secure: true, httpOnly: false));

        return $response;
    }

    private function failure(string $outcome): RedirectResponse
    {
        return $this->redirect('/login?telegram='.$outcome);
    }

    private function redirect(string $location): RedirectResponse
    {
        $response = new RedirectResponse($location, Response::HTTP_SEE_OTHER, self::HEADERS);
        $response->headers->setCookie($this->stateCookie('', 1));

        return $response;
    }

    private function stateCookie(string $value, int $expiresAt): Cookie
    {
        return Cookie::create(self::COOKIE, $value, $expiresAt, self::PATH, secure: true, httpOnly: true);
    }
}
