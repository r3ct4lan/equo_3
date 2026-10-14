<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Logging;

use App\Infrastructure\Logging\InvitationRedactionProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InvitationRedactionProcessorTest extends TestCase
{
    public function testDatabaseContextDoesNotExposeStatementsOrBoundHashes(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'doctrine', Level::Debug, 'Executing statement: {sql} (parameters: {params})', ['sql' => 'SELECT token_hash FROM connect_invitation', 'params' => [str_repeat('a', 64)], 'nested' => ['parameters' => [str_repeat('b', 64)]]]);
        $safe = (new InvitationRedactionProcessor())($record);
        self::assertSame('[redacted]', $safe->context['sql']);
        self::assertSame('[redacted]', $safe->context['params']);
        self::assertSame('[redacted]', $safe->context['nested']['parameters']);
    }

    public function testRouteParametersUrlsAndExceptionMessagesAreRedacted(): void
    {
        $token = '00000000-0000-4000-8000-000000000001.'.str_repeat('a', 43);
        $record = new LogRecord(new DateTimeImmutable(), 'request', Level::Info, 'Matched /connect-invitations/'.$token, ['route_parameters' => ['token' => $token], 'request_uri' => '/api/v1/connect-invitations/'.$token.'/accept', 'exception' => new RuntimeException($token)], ['path' => $token]);
        $safe = (new InvitationRedactionProcessor())($record);
        self::assertStringNotContainsString($token, json_encode($safe->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('[redacted]', $safe->context['route_parameters']['token']);
    }

    public function testCredentialCookiesHashesAndCiphertextAreRedactedAtAnyNestingLevel(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable(),
            'request',
            Level::Error,
            'authorization=Bearer eyJ'.str_repeat('a', 20).'.'.str_repeat('b', 20).'.'.str_repeat('c', 20).' SQLSTATE[23505]\nSELECT password_hash FROM app_user\n    at handler (/var/www/app.php:42)\npassword=guess-me hash='.str_repeat('e', 64),
            [
                'password_hash' => 'password-hash-secret',
                'refreshToken' => 'refresh-token-secret',
                'cookie' => 'equo_refresh=refresh-cookie-secret',
                'nested' => [
                    'csrf_token' => 'csrf-secret',
                    'encrypted_payload' => 'ciphertext-secret',
                    'idempotency_key_hash' => 'key-hash-secret',
                ],
            ],
        );

        $safe = (new InvitationRedactionProcessor())($record);
        $diagnostic = json_encode($safe->toArray(), JSON_THROW_ON_ERROR);
        self::assertSame('[redacted]', $safe->context['password_hash']);
        self::assertSame('[redacted]', $safe->context['nested']['csrf_token']);

        foreach ([
            'eyJ'.str_repeat('a', 20).'.'.str_repeat('b', 20).'.'.str_repeat('c', 20),
            'guess-me', 'password-hash-secret', 'refresh-token-secret',
            'refresh-cookie-secret', 'csrf-secret', 'ciphertext-secret', 'key-hash-secret',
            'SQLSTATE[23505]', '/var/www',
            str_repeat('e', 64),
        ] as $secret) {
            self::assertStringNotContainsString($secret, $diagnostic);
        }
    }
}
