<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryStatus;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ActivationRequestHttpTest extends WebTestCase
{
    private const string PASSWORD = 'Correct horse battery staple!';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testEligibleInactiveAccountGetsReplacementTokenAndOutboxWithoutSecretLeaks(): void
    {
        $user = $this->insertUser('resend@example.test', false);
        $oldToken = $this->insertPendingActivation($user);
        $handler = self::getContainer()->get('monolog.handler.api_test');
        $handler->clear();

        $body = $this->requestActivation('  Resend@Example.Test  ', self::PASSWORD);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['status' => 'activation_email_scheduled'], $body);
        self::assertSame(2, $this->countRows('user_action_token'));
        self::assertSame(2, $this->countRows('email_delivery_outbox'));
        self::assertNotFalse($this->connection->fetchOne(
            'SELECT invalidated_at FROM user_action_token WHERE id = ?',
            [$oldToken->id()],
        ));

        $replacement = $this->connection->fetchAssociative(
            'SELECT id, token_hash, created_at, expires_at, used_at, invalidated_at FROM user_action_token WHERE user_id = ? AND id <> ?',
            [$user->id(), $oldToken->id()],
        );
        self::assertIsArray($replacement);
        self::assertNull($replacement['used_at']);
        self::assertNull($replacement['invalidated_at']);
        self::assertSame(86_400, (int) $this->connection->fetchOne(
            'SELECT EXTRACT(EPOCH FROM (expires_at - created_at)) FROM user_action_token WHERE id = ?',
            [$replacement['id']],
        ));

        $encryptedPayload = $this->connection->fetchOne(
            'SELECT encrypted_payload FROM email_delivery_outbox WHERE user_action_token_id = ?',
            [$replacement['id']],
        );
        if (is_resource($encryptedPayload)) {
            $encryptedPayload = stream_get_contents($encryptedPayload);
        }
        self::assertIsString($encryptedPayload);
        $publicToken = self::getContainer()->get(PayloadCipher::class)->decrypt($encryptedPayload)['token'];
        self::assertStringNotContainsString($publicToken, $encryptedPayload);
        $responseContent = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::PASSWORD, $responseContent);
        self::assertStringNotContainsString($publicToken, $responseContent);
        self::assertStringNotContainsString($publicToken, (string) $replacement['token_hash']);
        $passwordHash = $this->connection->fetchOne(
            'SELECT password_hash FROM app_user WHERE id = ?',
            [$user->id()],
        );
        self::assertIsString($passwordHash);
        self::assertStringNotContainsString(self::PASSWORD, $passwordHash);
        self::assertSame([], $handler->getRecords());
    }

    public function testUnknownEmailGetsSameResponseWithoutDatabaseChanges(): void
    {
        $body = $this->requestActivation('unknown@example.test', self::PASSWORD);

        $this->assertPublicNoop($body);
        self::assertSame(0, $this->countRows('app_user'));
    }

    public function testWrongPasswordGetsSameResponseWithoutDatabaseChanges(): void
    {
        $user = $this->insertUser('wrong-password@example.test', false);
        $storedHash = $user->passwordHash();

        $body = $this->requestActivation('wrong-password@example.test', 'Wrong password!');

        $this->assertPublicNoop($body);
        self::assertSame($storedHash, $this->connection->fetchOne(
            'SELECT password_hash FROM app_user WHERE id = ?',
            [$user->id()],
        ));
    }

    public function testActiveAccountGetsSameResponseWithoutDatabaseChanges(): void
    {
        $user = $this->insertUser('active@example.test', true);

        $body = $this->requestActivation('active@example.test', self::PASSWORD);

        $this->assertPublicNoop($body);
        self::assertSame(true, $this->connection->fetchOne(
            'SELECT is_active FROM app_user WHERE id = ?',
            [$user->id()],
        ));
    }

    public function testInvalidBodyUsesStandardValidationErrorWithoutWrites(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/activation-requests',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'not-an-email'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = $this->jsonBody();
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
        self::assertSame(['email', 'password'], array_column($body['error']['details']['violations'], 'field'));
        self::assertSame(0, $this->countRows('app_user'));
        self::assertSame(0, $this->countRows('user_action_token'));
        self::assertSame(0, $this->countRows('email_delivery_outbox'));
    }

    private function insertUser(string $email, bool $active): UserRecord
    {
        $user = new UserRecord(
            Uuid::v7()->toRfc4122(),
            'Activation Request User',
            $email,
            self::getContainer()->get(PasswordHashingPort::class)->hash(self::PASSWORD),
            $active,
            new DateTimeImmutable('-1 day'),
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function insertPendingActivation(UserRecord $user): UserActionTokenRecord
    {
        $now = new DateTimeImmutable('-1 hour');
        $token = new UserActionTokenRecord(
            Uuid::v7()->toRfc4122(),
            $user,
            'v1:old-token-hash-'.bin2hex(random_bytes(8)),
            UserActionTokenPurpose::ActivateAccount,
            null,
            $now,
            $now->modify('+24 hours'),
        );
        $outbox = new EmailDeliveryOutboxRecord(
            Uuid::v7()->toRfc4122(),
            $token->id(),
            $user->email(),
            'activate-account',
            self::getContainer()->get(PayloadCipher::class)->encrypt(['token' => 'v1.old-public-token']),
            EmailDeliveryStatus::Pending,
            $now,
            $now,
            null,
            null,
            null,
            0,
            null,
        );
        $this->entityManager->persist($token);
        $this->entityManager->persist($outbox);
        $this->entityManager->flush();

        return $token;
    }

    /** @return array<string, mixed> */
    private function requestActivation(string $email, string $password): array
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/activation-requests',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );

        return $this->jsonBody();
    }

    /** @param array<string, mixed> $body */
    private function assertPublicNoop(array $body): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame(['status' => 'activation_email_scheduled'], $body);
        self::assertSame(0, $this->countRows('user_action_token'));
        self::assertSame(0, $this->countRows('email_delivery_outbox'));
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
