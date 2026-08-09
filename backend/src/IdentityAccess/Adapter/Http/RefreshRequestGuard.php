<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

final readonly class RefreshRequestGuard
{
    private string $applicationOrigin;

    public function __construct(string $applicationOrigin)
    {
        $this->applicationOrigin = $this->canonicalOrigin($applicationOrigin);
    }

    public function validate(Request $request): RefreshRequestCredentials
    {
        $refreshToken = $request->cookies->getString('equo_refresh');

        if ('' === $refreshToken) {
            throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
        }

        if ($request->headers->get('Origin') !== $this->applicationOrigin) {
            throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if (null !== $fetchSite && !in_array($fetchSite, ['same-origin', 'none'], true)) {
            throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
        }

        $csrfCookie = $request->cookies->getString('__Host-equo_csrf');
        $csrfHeader = $request->headers->get('X-CSRF-Token', '');

        if ('' === $csrfCookie || '' === $csrfHeader || !hash_equals($csrfCookie, $csrfHeader)) {
            throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
        }

        return new RefreshRequestCredentials($refreshToken, $csrfHeader);
    }

    private function canonicalOrigin(string $origin): string
    {
        $parts = parse_url($origin);

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['path'], $parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || '' === $parts['host']
        ) {
            throw new InvalidArgumentException('The application origin is invalid.');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $canonical = $parts['scheme'].'://'.$parts['host'].$port;

        if ($origin !== $canonical) {
            throw new InvalidArgumentException('The application origin is invalid.');
        }

        return $canonical;
    }
}
