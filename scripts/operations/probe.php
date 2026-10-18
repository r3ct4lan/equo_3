<?php

declare(strict_types=1);

// Fixtures and assertions are permitted only in the disposable operations DB.
require '/var/www/backend/vendor/autoload.php';

use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmail;
use App\Infrastructure\EmailDelivery\Messaging\UserActionEmailRetryStrategy;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Uid\Uuid;

final class ProbeFailure extends RuntimeException
{
}

function ensure(bool $condition, string $label): void
{
    if (!$condition) {
        throw new ProbeFailure($label);
    }
}

function uuid(): string
{
    return Uuid::v4()->toRfc4122();
}

try {
    $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse((string) getenv('DATABASE_URL'));
    ensure('1' === getenv('EQUO_OPS_TEST') && 'equo_ops_test' === ($params['dbname'] ?? '')
        && 'postgres' === ($params['host'] ?? '') && 'smtp://smtp:1025' === getenv('MAILER_DSN'), 'isolation guard');
    $db = DriverManager::getConnection($params);
    $stateFile = '/probe-state/fixtures.json';
    $state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR) : [];
    $action = $argv[1] ?? '';
    $label = $argv[2] ?? '';
    $result = ['ok' => true];

    if ('register' === $action) {
        ensure((bool) preg_match('/^[a-z]+$/D', $label), 'fixture label');
        $kernel = new App\Kernel('prod', false);
        $request = Request::create('https://equo-ops.test/api/v1/auth/register', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => uuid(), 'REMOTE_ADDR' => '192.0.2.'.(count($state) + 1),
        ], content: json_encode(['name' => 'Operations test', 'email' => $label.'@example.test',
            'password' => 'Ops!'.bin2hex(random_bytes(20))], JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        ensure(201 === $response->getStatusCode(), 'registration status '.$response->getStatusCode());
        $kernel->terminate($request, $response);
        $id = $db->fetchOne('SELECT id FROM email_delivery_outbox WHERE recipient_email = ?', [$label.'@example.test']);
        ensure(is_string($id), 'registration durable intent');
        $state[$label] = $id;
        file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    } elseif ('status' === $action) {
        $row = $db->fetchAssociative('SELECT status, encrypted_payload IS NULL AS cleared, publish_attempts FROM email_delivery_outbox WHERE id = ?', [$state[$label]]);
        $result = $row ?: ['status' => 'MISSING'];
    } elseif ('id' === $action) {
        $result = ['id' => $state[$label]]; // Internal runner input, never printed by the runner.
    } elseif ('retry-schedule' === $action) {
        $strategy = new UserActionEmailRetryStrategy();
        foreach ([60000, 300000, 900000, 3600000, 21600000] as $count => $delay) {
            $e = new Envelope(new SendUserActionEmail(uuid()), [new RedeliveryStamp($count)]);
            ensure($strategy->isRetryable($e) && $delay === $strategy->getWaitingTime($e), 'retry schedule');
        }
        ensure(!$strategy->isRetryable(new Envelope(new SendUserActionEmail(uuid()), [new RedeliveryStamp(5)])), 'retry exhaustion');
    } elseif ('seed-final-attempt' === $action) {
        // Accelerate only the message fixture, not the production retry strategy.
        // The first real 60-second retry is exercised separately by the runner.
        $transport = (new AmqpTransportFactory())->createTransport((string) getenv('MESSENGER_TRANSPORT_DSN'),
            ['auto_setup' => true, 'confirm_timeout' => 5], new PhpSerializer());
        $found = false;
        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();
            ensure($message instanceof SendUserActionEmail && $state[$label] === $message->deliveryId, 'queued fixture identity');
            $transport->send(new Envelope($message, [new RedeliveryStamp(5)]));
            $transport->ack($envelope);
            $found = true;
            break;
        }
        ensure($found, 'queued fixture present');
    } elseif ('business-counts' === $action) {
        $result = [];
        foreach (['app_user', 'user_action_token', 'email_delivery_outbox'] as $table) {
            $result[$table] = (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table);
        }
    } elseif ('seed-retention' === $action) {
        ensure(!isset($state['retention']), 'retention fixture is fresh');
        $ids = [];
        foreach (['old', 'recent', 'active', 'expired'] as $kind) {
            $user = uuid();
            $token = uuid();
            $outbox = uuid();
            $db->insert('app_user', ['id' => $user, 'name' => 'Retention fixture', 'email' => $user.'@example.test',
                'password_hash' => 'unused-test-fixture', 'is_active' => 'false', 'created_at' => '2026-01-01T00:00:00Z']);
            $age = match ($kind) { 'old' => '40 days', 'recent' => '1 day', 'expired' => '60 days', default => '0 days' };
            $expiry = 'active' === $kind ? "CURRENT_TIMESTAMP + INTERVAL '1 day'" : "CURRENT_TIMESTAMP - INTERVAL '$age'";
            $used = in_array($kind, ['old', 'recent'], true) ? "CURRENT_TIMESTAMP - INTERVAL '$age'" : 'NULL';
            $db->executeStatement("INSERT INTO user_action_token (id,user_id,token_hash,purpose,created_at,expires_at,used_at)
                VALUES (?, ?, ?, 'ACTIVATE_ACCOUNT', CURRENT_TIMESTAMP - INTERVAL '61 days', $expiry, $used)", [$token, $user, 'fixture-'.$token]);
            $terminal = in_array($kind, ['old', 'recent'], true);
            $sent = $terminal ? "CURRENT_TIMESTAMP - INTERVAL '$age'" : 'NULL';
            $db->executeStatement("INSERT INTO email_delivery_outbox
                (id,user_action_token_id,recipient_email,template_key,encrypted_payload,status,created_at,available_at,sent_at,publish_attempts)
                VALUES (?, ?, 'fixture@example.test', 'activate-account', decode('0102','hex'), ?,
                CURRENT_TIMESTAMP - INTERVAL '61 days', CURRENT_TIMESTAMP, $sent, 0)", [$outbox, $token, $terminal ? 'SENT' : 'PENDING']);
            $ids[$kind] = ['token' => $token, 'outbox' => $outbox];
            if ('expired' === $kind) {
                continue;
            }
            $session = uuid();
            $invitation = uuid();
            $idempotency = uuid();
            $db->executeStatement("INSERT INTO user_session (id,user_id,refresh_token_hash,created_at,expires_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP - INTERVAL '62 days', $expiry)", [$session, $user, 'fixture-'.$session]);
            $db->executeStatement("INSERT INTO connect_invitation (id,created_by_id,token_hash,created_at,expires_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP - INTERVAL '62 days', $expiry)", [$invitation, $user, 'fixture-'.$invitation]);
            $idemExpiry = 'old' === $kind ? "CURRENT_TIMESTAMP - INTERVAL '1 day'" : "CURRENT_TIMESTAMP + INTERVAL '1 hour'";
            $db->executeStatement("INSERT INTO idempotency_record
                (id,scope,user_id,operation,idempotency_key,request_hash,response_status,response_body,created_at,expires_at)
                VALUES (?, ?, ?, 'ops.fixture', ?, 'fixture', 200, '{}', CURRENT_TIMESTAMP - INTERVAL '2 days', $idemExpiry)",
                [$idempotency, 'user:'.$user, $user, 'fixture-'.$idempotency]);
            $ids[$kind] += ['session' => $session, 'invitation' => $invitation, 'idempotency' => $idempotency];
        }
        $state['retention'] = $ids;
        file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    } elseif ('check-retention' === $action) {
        $tables = ['token' => 'user_action_token', 'outbox' => 'email_delivery_outbox',
            'session' => 'user_session', 'invitation' => 'connect_invitation', 'idempotency' => 'idempotency_record'];
        foreach ($state['retention'] as $kind => $ids) {
            foreach ($ids as $type => $id) {
                $count = (int) $db->fetchOne('SELECT COUNT(*) FROM '.$tables[$type].' WHERE id = ?', [$id]);
                ensure(('old' === $kind ? 0 : 1) === $count, 'retention '.$kind.' '.$type);
            }
        }
        foreach (['recent', 'expired'] as $kind) {
            ensure(0 === (int) $db->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox WHERE id = ? AND encrypted_payload IS NOT NULL', [$state['retention'][$kind]['outbox']]), 'retention payload cleared');
        }
        ensure('FAILED' === $db->fetchOne('SELECT status FROM email_delivery_outbox WHERE id = ?', [$state['retention']['expired']['outbox']]), 'expired intent terminalized');
        ensure('PENDING' === $db->fetchOne('SELECT status FROM email_delivery_outbox WHERE id = ? AND encrypted_payload IS NOT NULL', [$state['retention']['active']['outbox']]), 'active intent preserved');
    } else {
        throw new RuntimeException('unknown probe action');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Never print exception messages, SQL, tokens or stack traces.
    fwrite(STDERR, 'Operations probe failed: '.($action ?? 'guard').' ['.$error::class."]\n");
    if ($error instanceof ProbeFailure) {
        fwrite(STDERR, $error->getMessage()."\n");
    }
    exit(1);
}
