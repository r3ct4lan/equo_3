<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\RefreshRequestGuard;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RefreshRequestGuardTest extends TestCase
{
    public function testValidRequestReturnsOnlyRefreshAndCsrfTokens(): void
    {
        $credentials = (new RefreshRequestGuard('https://equo.test'))->validate($this->request());

        self::assertSame('rt.public', $credentials->refreshToken);
        self::assertSame('csrf.public', $credentials->csrfToken);
    }

    #[DataProvider('forbiddenRequestProvider')]
    public function testForbiddenPreflightBranches(string $origin, ?string $fetchSite, ?string $csrfCookie, ?string $csrfHeader): void
    {
        try {
            (new RefreshRequestGuard('https://equo.test'))->validate($this->request(
                origin: $origin,
                fetchSite: $fetchSite,
                csrfCookie: $csrfCookie,
                csrfHeader: $csrfHeader,
            ));
            self::fail('Invalid refresh preflight must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::Forbidden, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, string|null, string|null, string|null}> */
    public static function forbiddenRequestProvider(): iterable
    {
        yield 'missing origin' => ['', 'same-origin', 'csrf.public', 'csrf.public'];
        yield 'wrong scheme' => ['http://equo.test', 'same-origin', 'csrf.public', 'csrf.public'];
        yield 'wrong host' => ['https://evil.test', 'same-origin', 'csrf.public', 'csrf.public'];
        yield 'wrong port' => ['https://equo.test:8443', 'same-origin', 'csrf.public', 'csrf.public'];
        yield 'prefix origin' => ['https://equo.test.evil.test', 'same-origin', 'csrf.public', 'csrf.public'];
        yield 'null origin' => ['null', 'same-origin', 'csrf.public', 'csrf.public'];
        yield 'same-site fetch' => ['https://equo.test', 'same-site', 'csrf.public', 'csrf.public'];
        yield 'cross-site fetch' => ['https://equo.test', 'cross-site', 'csrf.public', 'csrf.public'];
        yield 'empty fetch' => ['https://equo.test', '', 'csrf.public', 'csrf.public'];
        yield 'unknown fetch' => ['https://equo.test', 'navigate', 'csrf.public', 'csrf.public'];
        yield 'missing csrf cookie' => ['https://equo.test', 'same-origin', null, 'csrf.public'];
        yield 'missing csrf header' => ['https://equo.test', 'same-origin', 'csrf.public', null];
        yield 'csrf mismatch' => ['https://equo.test', 'same-origin', 'csrf.public', 'other'];
    }

    public function testMissingRefreshCookieIsAuthenticationRequired(): void
    {
        try {
            (new RefreshRequestGuard('https://equo.test'))->validate($this->request(refreshToken: ''));
            self::fail('Missing refresh cookie must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::AuthenticationRequired, $failure->failureCode);
        }
    }

    public function testValidFetchMetadataValuesAreAccepted(): void
    {
        foreach ([null, 'same-origin', 'none'] as $fetchSite) {
            $credentials = (new RefreshRequestGuard('https://equo.test'))->validate($this->request(fetchSite: $fetchSite));

            self::assertSame('rt.public', $credentials->refreshToken);
        }
    }

    public function testApplicationOriginRejectsNonOriginConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RefreshRequestGuard('https://equo.test/path');
    }

    private function request(
        string $refreshToken = 'rt.public',
        string $origin = 'https://equo.test',
        ?string $fetchSite = 'same-origin',
        ?string $csrfCookie = 'csrf.public',
        ?string $csrfHeader = 'csrf.public',
    ): Request {
        $request = Request::create('/api/v1/auth/refresh', 'POST');
        if ('' !== $refreshToken) {
            $request->cookies->set('equo_refresh', $refreshToken);
        }
        if ('' !== $origin) {
            $request->headers->set('Origin', $origin);
        }
        if (null !== $fetchSite) {
            $request->headers->set('Sec-Fetch-Site', $fetchSite);
        }
        if (null !== $csrfCookie) {
            $request->cookies->set('__Host-equo_csrf', $csrfCookie);
        }
        if (null !== $csrfHeader) {
            $request->headers->set('X-CSRF-Token', $csrfHeader);
        }

        return $request;
    }
}
