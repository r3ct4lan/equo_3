<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\RegisteredClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MeHttpTest extends WebTestCase
{
    private const string TEST_TELEGRAM_TOKEN = 'equo-test-mini-app-key';
    private const string ORIGIN = 'https://equo.test';
    private const string AUDIENCE = 'equo-api';
    private const string ISSUER = 'https://equo.test';
    private const string CSRF_KEY_RING = '{"v1":"bWUtY3NyZi1rZXktZm9yLWJyb3dzZXItdGVzdHMtMzIh"}';

    private KernelBrowser $client;
    private Connection $connection;
    private PasswordHashingPort $passwordHasher;
    private ?string $oidcChallenge = null;

    /** @var array{private: string, public: string} */
    private array $keys;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->keys = self::rsaKeyPair();
        $this->configureEnvironment();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->passwordHasher = self::getContainer()->get(PasswordHashingPort::class);
        $this->connection->beginTransaction();
        self::getContainer()->get('test.rate_limiter_cache')->clear();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testMeReturnsFreshCurrentUserWithoutCookiesOrSessionRotation(): void
    {
        $userId = '87000000-0000-4000-8000-000000000301';
        $this->insertUser($userId, 'Me User', 'me@example.test', true, 'Correct password!', '2026-07-26 18:42:15+00');
        $login = $this->login('me@example.test');
        $oldHash = $this->storedHash();
        $oldSessionCount = $this->countSessions();

        $body = $this->me($login['accessToken']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHasHeader('X-Request-Id');
        self::assertSame(['createdAt', 'email', 'id', 'isActive', 'locale', 'name'], $this->sortedKeys($body));
        self::assertSame($userId, $body['id']);
        self::assertSame('Me User', $body['name']);
        self::assertSame('me@example.test', $body['email']);
        self::assertTrue($body['isActive']);
        self::assertSame('2026-07-26T18:42:15Z', $body['createdAt']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame($oldHash, $this->storedHash());
        self::assertSame($oldSessionCount, $this->countSessions());
        self::assertStringNotContainsString('accessToken', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('password', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('roles', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testMeReturnsProfileForTelegramOnlyAccount(): void
    {
        $userId = '87000000-0000-4000-8000-000000000309';
        $this->connection->insert('app_user', [
            'id' => $userId,
            'name' => 'Telegram User',
            'telegram_id' => 4294967297,
            'is_active' => 'true',
            'created_at' => '2026-11-21 12:00:00+00',
        ]);
        $access = self::getContainer()->get(AccessTokenIssuerPort::class)->issue($userId)->accessToken;

        $profile = $this->me($access);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($userId, $profile['id']);
        self::assertSame('Telegram User', $profile['name']);
        self::assertArrayHasKey('email', $profile);
        self::assertNull($profile['email']);
        self::assertArrayNotHasKey('telegramId', $profile);

        $this->client->request('GET', '/api/v1/debt-people?q=Telegram', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$access,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([[
            'id' => $userId,
            'name' => 'Telegram User',
            'isActive' => true,
            'email' => null,
        ]], $this->jsonBody()['items']);
    }

    public function testTelegramLaunchIssuesSessionAndReusesTheAccount(): void
    {
        $data = $this->signedTelegramData(['id' => 4294967297, 'first_name' => 'Telegram', 'last_name' => 'User']);
        $first = $this->telegramLaunch($data);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('Telegram User', $first['user']['name']);
        self::assertNull($first['user']['email']);
        self::assertArrayNotHasKey('telegramId', $first['user']);
        self::assertEqualsCanonicalizing(['equo_refresh', '__Host-equo_csrf'], array_keys($this->responseCookies()));
        $second = $this->telegramLaunch($data);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($first['user']['id'], $second['user']['id']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM app_user WHERE telegram_id = ?', [4294967297]));
        self::assertSame(2, $this->countSessions());
    }

    public function testTelegramLaunchReturnsOnlyAVerifiedStartParameter(): void
    {
        $fixture = ['id' => 4294967297, 'first_name' => 'Invite Recipient'];
        $data = $this->signedTelegramData($fixture, startParam: 'invite_abc123');
        $result = $this->telegramLaunch($data);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('invite_abc123', $result['startParam']);

        $result = $this->telegramLaunch($this->signedTelegramData($fixture).'&start_param=invite_unsigned');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertArrayNotHasKey('startParam', $result);
    }

    public function testTelegramLaunchRejectsInvalidExpiredAndIncompleteProofs(): void
    {
        $valid = $this->signedTelegramData(['id' => 4294967297, 'first_name' => 'Telegram']);
        foreach ([
            str_replace('Telegram', 'Modified', $valid),
            $this->signedTelegramData(['id' => 4294967297], -301),
            $this->signedTelegramData(['id' => 4294967297], 31),
            $this->signedTelegramData(['first_name' => 'No ID']),
            $this->signedTelegramData(['id' => 0]),
            $valid.'&auth_date=1',
        ] as $invalid) {
            $body = $this->telegramLaunch($invalid);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
            self::assertSame('TELEGRAM_AUTHENTICATION_FAILED', $body['error']['code']);
            self::assertSame([], $this->responseCookies());
        }

        self::assertSame(0, $this->countSessions());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM app_user WHERE telegram_id IS NOT NULL'));
    }

    public function testTelegramLaunchRequiresSameOriginAndRejectsInactiveUser(): void
    {
        $id = 4294967297;
        $data = $this->signedTelegramData(['id' => $id]);
        $this->telegramLaunch($data, 'https://another.test');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->connection->insert('app_user', [
            'id' => '87000000-0000-4000-8000-000000000309',
            'name' => 'Inactive Telegram User',
            'telegram_id' => $id,
            'is_active' => 'false',
            'created_at' => '2026-11-21 12:00:00+00',
        ]);
        $result = $this->telegramLaunch($data);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('ACCOUNT_INACTIVE', $result['error']['code']);
        self::assertSame([], $this->responseCookies());
        self::assertSame(0, $this->countSessions());
    }

    public function testTelegramMiniAppRequiresExplicitReactivationAndReturnsTheSameAccount(): void
    {
        $identity = $this->signedTelegramData(['id' => 4294967297]);
        $login = $this->telegramLaunch($identity);
        $userId = $login['user']['id'];
        $this->connection->update('app_user', ['is_active' => 'false'], ['id' => $userId]);
        $this->connection->executeStatement('UPDATE user_session SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ?', [$userId]);

        $this->telegramLaunch($identity);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, $this->countSessions());

        $activated = $this->telegramLaunch($identity, path: '/reactivate');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($userId, $activated['user']['id']);
        self::assertSame(2, $this->countSessions());

        $result = $this->telegramLaunch($identity, path: '/reactivate');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('CREDENTIAL_CONFLICT', $result['error']['code']);
        self::assertSame(2, $this->countSessions());
    }

    public function testTelegramOidcReactivationRequiresOneConfirmationInTheSameBrowser(): void
    {
        $telegramId = 4294967297;
        $this->mockTelegramOidcProvider($this->telegramIdToken($telegramId));
        $login = $this->telegramLaunch($this->signedTelegramData(['id' => $telegramId]));
        $userId = $login['user']['id'];
        $this->connection->update('app_user', ['is_active' => 'false'], ['id' => $userId]);
        $this->connection->executeStatement('UPDATE user_session SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ?', [$userId]);
        $reactivate = self::ORIGIN.'/api/v1/auth/telegram/oidc/reactivate';
        $query = $this->telegramOidcStart();

        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects('/login?telegram=inactive');
        self::assertArrayNotHasKey('equo_refresh', $this->responseCookies());
        self::assertFalse((bool) $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$userId]));
        self::assertSame(1, $this->countSessions());

        $cookieJar = $this->client->getCookieJar();
        $cookie = $cookieJar->get('__Secure-equo_telegram_reactivate', '/api/v1/auth/telegram/oidc/reactivate', 'equo.test');
        self::assertInstanceOf(BrowserKitCookie::class, $cookie);
        $cookieJar->clear();
        $this->client->request('POST', $reactivate, server: ['HTTP_ORIGIN' => self::ORIGIN]);
        self::assertResponseRedirects('/login?telegram=failed');
        self::assertSame(1, $this->countSessions());

        $cookieJar->set($cookie);
        $this->client->request('POST', $reactivate, server: ['HTTP_ORIGIN' => self::ORIGIN]);
        self::assertResponseRedirects('/');
        self::assertArrayHasKey('equo_refresh', $this->responseCookies());
        self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$userId]));
        self::assertSame(2, $this->countSessions());

        $cookieJar->set($cookie);
        $this->client->request('POST', $reactivate, server: ['HTTP_ORIGIN' => self::ORIGIN]);
        self::assertResponseRedirects('/login?telegram=failed');
        self::assertSame(2, $this->countSessions());
    }

    #[DataProvider('telegramDeactivationIdentities')]
    public function testTelegramDeactivationRequiresTheSameIdentityAndExplicitConfirmation(int $telegramId): void
    {
        $this->mockTelegramOidcProvider($this->telegramIdToken($telegramId));
        $identity = $this->signedTelegramData(['id' => 4294967297]);
        $first = $this->telegramLaunch($identity);
        $second = $this->telegramLaunch($identity);
        $userId = $first['user']['id'];

        $this->credentialRequest('/telegram/deactivation/confirm', $first['accessToken'], []);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $state = $this->startTelegramCredential($first['accessToken'], '/deactivation/start');
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $state,
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects(4294967297 === $telegramId ? '/me?credentials=deactivation_ready' : '/me?credentials=failed');
        self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$userId]));

        $this->credentialRequest('/telegram/deactivation/confirm', $second['accessToken'], []);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        if (4294967297 !== $telegramId) {
            $this->credentialRequest('/telegram/deactivation/confirm', $first['accessToken'], []);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$userId]));

            return;
        }
        $this->credentialRequest('/telegram/deactivation/cancel', $first['accessToken'], []);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->credentialRequest('/telegram/deactivation/confirm', $first['accessToken'], []);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$userId]));

        $state = $this->startTelegramCredential($first['accessToken'], '/deactivation/start');
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $state,
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects('/me?credentials=deactivation_ready');
        $this->credentialRequest('/telegram/deactivation/confirm', $first['accessToken'], []);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertFalse((bool) $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$userId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NULL', [$userId]));
        $this->me($second['accessToken']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return iterable<string, array{int}> */
    public static function telegramDeactivationIdentities(): iterable
    {
        yield 'same identity' => [4294967297];
        yield 'another identity' => [4294967298];
    }

    public function testTelegramOidcExchangesTheCodeAndUsesTheSameAccountAsMiniApp(): void
    {
        $this->mockTelegramOidcProvider($this->telegramIdToken(4294967297));
        $query = $this->telegramOidcStart();
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects('/');
        self::assertArrayHasKey('equo_refresh', $this->responseCookies());
        $browserUser = $this->connection->fetchOne('SELECT id FROM app_user WHERE telegram_id = ?', [4294967297]);
        self::assertIsString($browserUser);
        $miniApp = $this->telegramLaunch($this->signedTelegramData(['id' => 4294967297, 'first_name' => 'Another name']));
        self::assertSame($browserUser, $miniApp['user']['id']);
        self::assertSame('Web User', $miniApp['user']['name']);

        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects('/login?telegram=failed');
        self::assertArrayNotHasKey('equo_refresh', $this->responseCookies());
        self::assertSame(2, $this->countSessions());
    }

    public function testMiniAppAccountIsReusedOnTheWebsite(): void
    {
        $miniApp = $this->telegramLaunch($this->signedTelegramData(['id' => 4294967297, 'first_name' => 'Mini App User']));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->mockTelegramOidcProvider($this->telegramIdToken(4294967297));
        $query = $this->telegramOidcStart();
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects('/');
        self::assertArrayHasKey('equo_refresh', $this->responseCookies());
        self::assertSame($miniApp['user']['id'], $this->connection->fetchOne('SELECT id FROM app_user WHERE telegram_id = ?', [4294967297]));
        self::assertSame(2, $this->countSessions());
    }

    public function testTelegramOidcRejectsStateMismatchAndHandlesCancellation(): void
    {
        $query = $this->telegramOidcStart();
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?state='.str_repeat('x', 43).'&code=valid-code');
        self::assertResponseRedirects('/login?telegram=failed');
        self::assertArrayNotHasKey('equo_refresh', $this->responseCookies());

        $query = $this->telegramOidcStart();
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $query['state'],
            'error' => 'access_denied',
        ]));
        self::assertResponseRedirects('/login?telegram=cancelled');
        self::assertArrayNotHasKey('equo_refresh', $this->responseCookies());
        self::assertSame(0, $this->countSessions());
    }

    #[DataProvider('invalidTelegramOidcClaims')]
    public function testTelegramOidcRejectsInvalidToken(?int $telegramId, string $audience, bool $wrongSignature): void
    {
        $this->mockTelegramOidcProvider($this->telegramIdToken($telegramId, $audience, $wrongSignature));
        $query = $this->telegramOidcStart();
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects('/login?telegram=failed');
        self::assertArrayNotHasKey('equo_refresh', $this->responseCookies());
        self::assertSame(0, $this->countSessions());
    }

    /** @return iterable<string, array{?int, string, bool}> */
    public static function invalidTelegramOidcClaims(): iterable
    {
        yield 'missing Telegram ID' => [null, '123456789', false];
        yield 'wrong audience' => [4294967297, '123456780', false];
        yield 'wrong signature' => [4294967297, '123456789', true];
    }

    public function testEmailLinkRequiresTheInitiatingSessionAndOpensTheSameAccount(): void
    {
        $initData = $this->signedTelegramData(['id' => 4294967300]);
        $first = $this->telegramLaunch($initData);
        $second = $this->telegramLaunch($initData);
        $userId = $first['user']['id'];
        $email = 'link-new@example.test';
        $password = 'New correct password1!';

        $this->credentialRequest('/email/start', $first['accessToken'], ['email' => $email, 'password' => $password]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $token = $this->emailLinkToken($email);

        $this->credentialRequest('/email/confirm', $second['accessToken'], ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertNull($this->me($first['accessToken'])['email']);

        $this->credentialRequest('/email/confirm', $first['accessToken'], ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('linked', $this->jsonBody()['status']);
        self::assertSame($userId, $this->me($this->login($email, $password)['accessToken'])['id']);

        $this->credentialRequest('/email/confirm', $first['accessToken'], ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    public function testExpiredEmailLinkLeavesCredentialsUnchanged(): void
    {
        $session = $this->telegramLaunch($this->signedTelegramData(['id' => 4294967303]));
        $email = 'link-expired@example.test';
        $this->credentialRequest('/email/start', $session['accessToken'], ['email' => $email, 'password' => 'New correct password1!']);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $token = $this->emailLinkToken($email);
        $this->connection->executeStatement(
            "UPDATE user_action_token SET created_at = CURRENT_TIMESTAMP - INTERVAL '31 minutes', expires_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE user_id = ? AND purpose = 'LINK_EMAIL'",
            [$session['user']['id']],
        );

        $this->credentialRequest('/email/confirm', $session['accessToken'], ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertNull($this->me($session['accessToken'])['email']);
    }

    public function testEmailLinkCanBeCancelled(): void
    {
        $session = $this->telegramLaunch($this->signedTelegramData(['id' => 4294967301]));
        $email = 'link-cancel@example.test';
        $this->credentialRequest('/email/start', $session['accessToken'], ['email' => $email, 'password' => 'New correct password1!']);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $token = $this->emailLinkToken($email);

        $this->credentialRequest('/cancel', $session['accessToken'], []);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->credentialRequest('/email/confirm', $session['accessToken'], ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertNull($this->me($session['accessToken'])['email']);
    }

    public function testEmailLinkCanProveAccessToAnotherAccountWithoutMovingCredentials(): void
    {
        $targetId = '87000000-0000-4000-8000-000000000362';
        $email = 'link-existing@example.test';
        $this->insertUser($targetId, 'Email owner', $email, true, 'Correct password!');
        $session = $this->telegramLaunch($this->signedTelegramData(['id' => 4294967302]));

        $this->credentialRequest('/email/start', $session['accessToken'], ['email' => $email, 'password' => 'Wrong password']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->credentialRequest('/email/start', $session['accessToken'], ['email' => $email, 'password' => 'Correct password!']);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $this->credentialRequest('/email/confirm', $session['accessToken'], ['token' => $this->emailLinkToken($email)]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('merge_required', $this->jsonBody()['status']);
        self::assertNull($this->me($session['accessToken'])['email']);
        self::assertSame($targetId, $this->me($this->login($email)['accessToken'])['id']);
    }

    public function testTelegramOidcCredentialChangeIsSeparateFromLogin(): void
    {
        $userId = '87000000-0000-4000-8000-000000000363';
        $this->insertUser($userId, 'Web owner', 'link-web@example.test', true, 'Correct password!');
        $session = $this->login('link-web@example.test');
        $this->mockTelegramOidcProvider($this->telegramIdToken(4294967297));

        $state = $this->startTelegramCredential($session['accessToken']);
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $state,
            'error' => 'access_denied',
        ]));
        self::assertResponseRedirects('/me?credentials=cancelled');
        self::assertNull($this->connection->fetchOne('SELECT telegram_id FROM app_user WHERE id = ?', [$userId]));
        self::assertSame(1, $this->countSessions());

        $state = $this->startTelegramCredential($session['accessToken']);
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/callback?'.http_build_query([
            'state' => $state,
            'code' => 'valid-code',
        ]));
        self::assertResponseRedirects('/me?credentials=linked');
        self::assertArrayNotHasKey('equo_refresh', $this->responseCookies());
        self::assertSame($userId, $this->connection->fetchOne('SELECT id FROM app_user WHERE telegram_id = ?', [4294967297]));
        self::assertSame(1, $this->countSessions());
    }

    public function testTelegramReplacementPreservesOnlyTheConfirmingSession(): void
    {
        $userId = '87000000-0000-4000-8000-000000000360';
        $this->insertUser($userId, 'Alex', 'replace-telegram@example.test', true, 'Correct password!');
        $this->connection->update('app_user', ['telegram_id' => 4294967297], ['id' => $userId]);
        $first = $this->login('replace-telegram@example.test');
        $second = $this->telegramLaunch($this->signedTelegramData(['id' => 4294967297]));
        $newProof = $this->signedTelegramData(['id' => 4294967298]);

        $this->credentialRequest('/telegram/proof', $first['accessToken'], ['initData' => $newProof, 'currentPassword' => 'Wrong password']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(4294967297, (int) $this->connection->fetchOne('SELECT telegram_id FROM app_user WHERE id = ?', [$userId]));

        $this->credentialRequest('/telegram/proof', $first['accessToken'], ['initData' => $newProof, 'currentPassword' => 'Correct password!']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($userId, $this->me($first['accessToken'])['id']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->me($second['accessToken']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $telegram = $this->telegramLaunch($newProof);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($userId, $telegram['user']['id']);
    }

    public function testTelegramCanBeLinkedToTheCurrentEmailAccount(): void
    {
        $userId = '87000000-0000-4000-8000-000000000361';
        $this->insertUser($userId, 'Alex', 'link-telegram@example.test', true, 'Correct password!');
        $login = $this->login('link-telegram@example.test');
        $proof = $this->signedTelegramData(['id' => 4294967297]);

        $this->credentialRequest('/telegram/proof', $login['accessToken'], ['initData' => $proof]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $miniApp = $this->telegramLaunch($proof);
        self::assertSame($userId, $miniApp['user']['id']);
        self::assertSame('link-telegram@example.test', $miniApp['user']['email']);
    }

    public function testMeReadsFreshDatabaseStateAndRejectsInactiveUserWithSameJwt(): void
    {
        $userId = '87000000-0000-4000-8000-000000000302';
        $this->insertUser($userId, 'Old Name', 'old@example.test', true, 'Correct password!');
        $login = $this->login('old@example.test');

        $this->connection->executeStatement('UPDATE app_user SET name = ?, email = ? WHERE id = ?', [
            'New Name',
            'new@example.test',
            $userId,
        ]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $fresh = $this->me($login['accessToken']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('New Name', $fresh['name']);
        self::assertSame('new@example.test', $fresh['email']);

        $this->connection->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$userId]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $inactive = $this->me($login['accessToken']);
        $this->assertAuthenticationRequired($inactive);
        self::assertArrayNotHasKey('name', $inactive);
    }

    public function testPatchMeChangesOnlyNameAndReturnsFreshProfile(): void
    {
        $userId = '87000000-0000-4000-8000-000000000306';
        $this->insertUser($userId, 'Old Name', 'rename@example.test', true, 'Correct password!');
        $login = $this->login('rename@example.test');
        $sessionCount = $this->countSessions();

        $body = $this->patchMe($login['accessToken'], ['name' => '  New Name 😀  ']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('  New Name 😀  ', $body['name']);
        self::assertSame('rename@example.test', $body['email']);
        self::assertSame('  New Name 😀  ', $this->connection->fetchOne('SELECT name FROM app_user WHERE id = ?', [$userId]));
        self::assertSame($sessionCount, $this->countSessions());
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testLocaleIsValidatedAndPersistedForAuthenticatedUser(): void
    {
        $userId = '87000000-0000-4000-8000-000000000308';
        $this->insertUser($userId, 'Locale User', 'locale@example.test', true, 'Correct password!');
        $login = $this->login('locale@example.test');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$login['accessToken']];

        $this->client->request('PATCH', '/api/v1/me/locale', server: $server, content: '{"locale":"ru"}');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('ru', $this->jsonBody()['locale']);
        self::assertSame('ru', $this->me($login['accessToken'])['locale']);
        self::assertSame('ru', $this->login('locale@example.test')['user']['locale']);

        $this->client->request('PATCH', '/api/v1/me/locale', server: $server, content: '{"locale":"fr"}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('ru', $this->connection->fetchOne('SELECT locale FROM app_user WHERE id = ?', [$userId]));

        $this->client->request('PATCH', '/api/v1/me/locale', server: ['CONTENT_TYPE' => 'application/json'], content: '{"locale":"en"}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidNameProvider')]
    public function testPatchMeValidatesNameWithoutChangingProfile(array $payload, int $status, string $code): void
    {
        $userId = '87000000-0000-4000-8000-000000000307';
        $this->insertUser($userId, 'Stable Name', 'rename-validation@example.test', true, 'Correct password!');
        $login = $this->login('rename-validation@example.test');

        $body = $this->patchMe($login['accessToken'], $payload);

        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $body['error']['code']);
        self::assertSame('Stable Name', $this->connection->fetchOne('SELECT name FROM app_user WHERE id = ?', [$userId]));
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function invalidNameProvider(): iterable
    {
        yield 'missing' => [[], 422, 'VALIDATION_ERROR'];
        yield 'blank' => [['name' => '   '], 422, 'VALIDATION_ERROR'];
        yield 'wrong type' => [['name' => 123], 422, 'VALIDATION_ERROR'];
        yield 'unsupported field' => [['name' => 'New', 'email' => 'new@example.test'], 400, 'INVALID_REQUEST'];
    }

    #[DataProvider('invalidAuthorizationProvider')]
    public function testMeAuthenticationFailuresUseOneSafe401(?string $authorization): void
    {
        $body = $this->meWithAuthorization($authorization);

        $this->assertAuthenticationRequired($body);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidAuthorizationProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'basic' => ['Basic abc'];
        yield 'empty bearer' => ['Bearer'];
        yield 'blank bearer' => ['Bearer '];
        yield 'malformed jwt' => ['Bearer not-a-jwt'];
    }

    #[DataProvider('invalidJwtProvider')]
    public function testMeRejectsInvalidJwtVariants(string $case): void
    {
        $body = $this->me($this->invalidJwt($case));

        $this->assertAuthenticationRequired($body);
        self::assertStringNotContainsString($case, (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJwtProvider(): iterable
    {
        yield 'invalid signature' => ['invalid-signature'];
        yield 'expired' => ['expired'];
        yield 'unknown kid' => ['unknown-kid'];
        yield 'wrong issuer' => ['wrong-issuer'];
        yield 'wrong audience' => ['wrong-audience'];
        yield 'wrong type' => ['wrong-type'];
        yield 'wrong algorithm' => ['wrong-algorithm'];
        yield 'missing claim' => ['missing-claim'];
        yield 'invalid subject' => ['invalid-subject'];
    }

    public function testMeRejectsValidTokensForUnknownAndInactiveUsers(): void
    {
        $unknown = self::getContainer()->get(AccessTokenIssuerPort::class)->issue('87000000-0000-4000-8000-000000000399')->accessToken;
        $this->assertAuthenticationRequired($this->me($unknown));

        $inactiveId = '87000000-0000-4000-8000-000000000303';
        $this->insertUser($inactiveId, 'Inactive User', 'inactive-me@example.test', false, 'Correct password!');
        $inactive = self::getContainer()->get(AccessTokenIssuerPort::class)->issue($inactiveId)->accessToken;
        $this->assertAuthenticationRequired($this->me($inactive));
    }

    public function testOnlyAuthorizationBearerAuthenticatesMe(): void
    {
        $userId = '87000000-0000-4000-8000-000000000304';
        $this->insertUser($userId, 'Cookie User', 'cookie-me@example.test', true, 'Correct password!');
        $login = $this->login('cookie-me@example.test');
        $cookies = $this->responseCookies();

        $this->client->getCookieJar()->set(new BrowserKitCookie('equo_refresh', $cookies['equo_refresh'], null, '/api/v1/auth', 'localhost', false));
        $this->client->getCookieJar()->set(new BrowserKitCookie('__Host-equo_csrf', $cookies['__Host-equo_csrf'], null, '/', 'localhost', false));
        $this->assertAuthenticationRequired($this->meWithAuthorization(null));

        $this->client->getCookieJar()->set(new BrowserKitCookie('accessToken', $login['accessToken'], null, '/', 'localhost', false));
        $this->assertAuthenticationRequired($this->meWithAuthorization(null));

        $this->client->request('GET', '/api/v1/me?accessToken='.rawurlencode($login['accessToken']));
        $this->assertAuthenticationRequired($this->jsonBody());

        $ok = $this->me($login['accessToken']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($userId, $ok['id']);
    }

    public function testPublicEndpointsRemainPublicAndRefreshKeepsItsOwnGuard(): void
    {
        $this->client->request('GET', '/api/health');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->request('POST', '/api/v1/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/auth/activate', server: ['CONTENT_TYPE' => 'application/json'], content: '{"token":"invalid"}');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->insertUser('87000000-0000-4000-8000-000000000305', 'Refresh User', 'refresh-public@example.test', true, 'Correct password!');
        $this->login('refresh-public@example.test');
        $cookies = $this->responseCookies();
        $this->client->getCookieJar()->set(new BrowserKitCookie('equo_refresh', $cookies['equo_refresh'], null, '/api/v1/auth', 'localhost', false));
        $this->client->getCookieJar()->set(new BrowserKitCookie('__Host-equo_csrf', $cookies['__Host-equo_csrf'], null, '/', 'localhost', false));
        $this->client->request('POST', '/api/v1/auth/refresh', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_CSRF_TOKEN' => $cookies['__Host-equo_csrf'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function login(string $email, string $password = 'Correct password!'): array
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.30'],
            content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $this->jsonBody();
    }

    /** @return array<string, mixed> */
    private function me(string $accessToken): array
    {
        return $this->meWithAuthorization('Bearer '.$accessToken);
    }

    /** @return array<string, mixed> */
    private function meWithAuthorization(?string $authorization): array
    {
        $server = [];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        $this->client->request('GET', '/api/v1/me', server: $server);

        return $this->jsonBody();
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function patchMe(string $accessToken, array $payload): array
    {
        $this->client->request('PATCH', '/api/v1/me', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->jsonBody();
    }

    /** @param array<string, mixed> $body */
    private function assertAuthenticationRequired(array $body): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHasHeader('X-Request-Id');
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertArrayHasKey('error', $body);
        self::assertSame([
            'code' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication is required.',
            'requestId' => $this->client->getResponse()->headers->get('X-Request-Id'),
        ], $body['error']);
        self::assertArrayNotHasKey('id', $body);
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, string> */
    private function responseCookies(): array
    {
        $cookies = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();
            self::assertIsString($value);
            $cookies[$cookie->getName()] = $value;
        }

        return $cookies;
    }

    private function insertUser(string $id, string $name, string $email, bool $active, string $password, string $createdAt = '2026-08-08 12:00:00+00'): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash($password),
            'is_active' => $active ? 1 : 0,
            'created_at' => $createdAt,
        ]);
    }

    private function emailLinkToken(string $email): string
    {
        $ciphertext = $this->connection->fetchOne(
            "SELECT encode(encrypted_payload, 'base64') FROM email_delivery_outbox WHERE recipient_email = ? AND template_key = 'link-email'",
            [$email],
        );
        if (!is_string($ciphertext) || false === ($payload = base64_decode($ciphertext, true))) {
            self::fail('The link email was not queued.');
        }

        return self::getContainer()->get(PayloadCipher::class)->decrypt($payload)['token'];
    }

    /** @param array<string, string> $payload */
    private function credentialRequest(string $path, string $accessToken, array $payload): void
    {
        $this->client->request('POST', '/api/v1/account/credentials'.$path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function startTelegramCredential(string $accessToken, string $path = '/start'): string
    {
        $this->client->request('POST', self::ORIGIN.'/api/v1/account/credentials/telegram'.$path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $url = $this->jsonBody()['url'];
        self::assertIsString($url);

        return $this->telegramOidcState($url);
    }

    /** @return array{state: string} */
    private function telegramOidcStart(): array
    {
        $this->client->request('GET', self::ORIGIN.'/api/v1/auth/telegram/oidc/start');
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertIsString($location);

        return ['state' => $this->telegramOidcState($location)];
    }

    private function telegramOidcState(string $location): string
    {
        self::assertStringStartsWith('https://oauth.telegram.org/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('openid profile', $query['scope']);
        self::assertIsString($query['state']);
        self::assertIsString($query['code_challenge']);

        $this->oidcChallenge = $query['code_challenge'];

        return $query['state'];
    }

    private function telegramIdToken(?int $telegramId, string $audience = '123456789', bool $wrongSignature = false): string
    {
        $pem = $wrongSignature ? self::rsaKeyPair()['private'] : $this->keys['private'];
        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::nonEmpty($pem)),
            InMemory::plainText(self::nonEmpty($this->keys['public'])),
        );
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $builder = $config->builder()->withHeader('kid', 'telegram-test-key')
            ->issuedBy('https://oauth.telegram.org')->permittedFor(self::nonEmpty($audience))
            ->relatedTo('opaque-subject')->issuedAt($now)->expiresAt($now->modify('+5 minutes'))
            ->withClaim('name', 'Web User');
        if (null !== $telegramId) {
            $builder = $builder->withClaim('id', $telegramId);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    private function mockTelegramOidcProvider(string $token): void
    {
        $key = openssl_pkey_get_public($this->keys['public']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['rsa']['n']);
        self::assertIsString($details['rsa']['e']);
        $keys = ['keys' => [[
            'kid' => 'telegram-test-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ]]];
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient(
            function (string $method, string $url, array $options) use ($token, $keys): MockResponse {
                if ('POST' === $method && 'https://oauth.telegram.org/token' === $url) {
                    self::assertIsString($options['body']);
                    parse_str($options['body'], $body);
                    self::assertIsString($body['code_verifier']);
                    self::assertSame($this->oidcChallenge, rtrim(strtr(base64_encode(hash('sha256', $body['code_verifier'], true)), '+/', '-_'), '='));
                    self::assertSame('valid-code', $body['code']);

                    return new MockResponse(json_encode(['id_token' => $token], JSON_THROW_ON_ERROR));
                }
                self::assertSame('https://oauth.telegram.org/.well-known/jwks.json', $url);

                return new MockResponse(json_encode($keys, JSON_THROW_ON_ERROR));
            },
        ));
    }

    /** @param array<string, int|string> $user */
    private function signedTelegramData(array $user, int $timeOffset = 0, ?string $startParam = null): string
    {
        $values = [
            'auth_date' => (string) (time() + $timeOffset),
            'user' => json_encode($user, JSON_THROW_ON_ERROR),
        ];
        if (null !== $startParam) {
            $values['start_param'] = $startParam;
        }
        ksort($values, SORT_STRING);
        $rows = [];
        foreach ($values as $k => $value) {
            $rows[] = $k.'='.$value;
        }
        $key = hash_hmac('sha256', self::TEST_TELEGRAM_TOKEN, 'WebAppData', true);
        $values['hash'] = hash_hmac('sha256', implode("\n", $rows), $key);

        return http_build_query($values, encoding_type: PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed> */
    private function telegramLaunch(string $initData, string $origin = self::ORIGIN, string $path = ''): array
    {
        $this->client->request('POST', '/api/v1/auth/telegram/mini-app'.$path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => $origin,
        ], content: json_encode(['initData' => $initData], JSON_THROW_ON_ERROR));

        return $this->jsonBody();
    }

    private function storedHash(): string
    {
        $hash = $this->connection->fetchOne('SELECT refresh_token_hash FROM user_session');
        self::assertIsString($hash);

        return $hash;
    }

    private function countSessions(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session');
    }

    /** @param array<string, mixed> $values
     * @return list<string>
     */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }

    private function invalidJwt(string $case): string
    {
        $otherKeys = self::rsaKeyPair();

        return match ($case) {
            'invalid-signature' => $this->buildToken(privateKey: $otherKeys['private']),
            'expired' => $this->buildToken(issuedAt: new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'))),
            'unknown-kid' => $this->buildToken(headers: ['kid' => 'v2']),
            'wrong-issuer' => $this->buildToken(issuer: 'https://issuer.invalid'),
            'wrong-audience' => $this->buildToken(audience: 'other-api'),
            'wrong-type' => $this->buildToken(headers: ['typ' => 'JWT']),
            'wrong-algorithm' => $this->buildHmacToken(),
            'missing-claim' => $this->buildToken(omit: [RegisteredClaims::SUBJECT]),
            'invalid-subject' => $this->buildToken(subject: 'not-a-uuid'),
            default => self::fail('Unknown invalid JWT case.'),
        };
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
        string $subject = '87000000-0000-4000-8000-000000000398',
        ?DateTimeImmutable $issuedAt = null,
        ?string $privateKey = null,
    ): string {
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::nonEmpty($privateKey ?? $this->keys['private'])),
            InMemory::plainText(self::nonEmpty($this->keys['public'])),
        );

        $issuedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
            $builder = $builder->identifiedBy('jti-me-test');
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
            ->relatedTo('87000000-0000-4000-8000-000000000397')
            ->issuedAt(new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->expiresAt(new DateTimeImmutable('+900 seconds', new DateTimeZone('UTC')))
            ->identifiedBy('jti-hmac')
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /** @return array{private: string, public: string} */
    private static function rsaKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
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
            self::fail('Expected non-empty string.');
        }

        return $value;
    }

    private function configureEnvironment(): void
    {
        $this->setEnv('EQUO_JWT_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_JWT_SIGNING_PRIVATE_KEY', base64_encode($this->keys['private']));
        $this->setEnv('EQUO_JWT_PUBLIC_KEY_RING', json_encode(['v1' => base64_encode($this->keys['public'])], JSON_THROW_ON_ERROR));
        $this->setEnv('EQUO_JWT_ISSUER', self::ISSUER);
        $this->setEnv('EQUO_JWT_AUDIENCE', self::AUDIENCE);
        $this->setEnv('EQUO_JWT_ACCESS_TTL_SECONDS', '900');
        $this->setEnv('EQUO_JWT_CLOCK_SKEW_SECONDS', '30');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_RING', self::CSRF_KEY_RING);
        $this->setEnv('EQUO_APPLICATION_ORIGIN', self::ORIGIN);
        $this->setEnv('EMAIL_PAYLOAD_KEY', base64_encode(str_repeat('e', 32)));
        $this->setEnv('EQUO_TELEGRAM_BOT_TOKEN', self::TEST_TELEGRAM_TOKEN);
        $this->setEnv('EQUO_TELEGRAM_CLIENT_ID', '123456789');
        $this->setEnv('EQUO_TELEGRAM_CLIENT_SECRET', 'equo-test-oidc-client');
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
