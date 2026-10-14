<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Throwable;

#[AsMonologProcessor]
final class InvitationRedactionProcessor
{
    /** @var list<string> */
    private const array SENSITIVE_KEYS = [
        'accessToken', 'authorization', 'ciphertext', 'cookie', 'csrfToken',
        'encryptedPayload', 'jwt', 'password', 'passwordHash', 'payload',
        'privateKey', 'refreshToken', 'secret', 'setCookie', 'token', 'tokenHash',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(message: $this->redact($record->message), context: $this->context($record->context), extra: $this->context($record->extra));
    }

    private function redact(string $text): string
    {
        return (string) preg_replace([
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[A-Za-z0-9_-]{43}/i',
            '/\brt\.[A-Za-z0-9_-]{20,}\b/',
            '/\brt2\.[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}\b/i',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            '/\bv\d+\.[A-Za-z0-9_-]{20,}(?:\.[A-Za-z0-9_-]{20,})?\b/',
            '/\b(?:sha256:)?[a-f0-9]{64}\b/i',
            '/([?&]token=)[^\s&"\']+/i',
            '/(authorization\s*[:=]\s*bearer\s+)[^\s,;]+/i',
            '/((?:set-)?cookie\s*[:=]\s*)[^\r\n]+/i',
            '/(x-csrf-token\s*[:=]\s*)[^\s,;]+/i',
            '/(\bpassword\s*[:=]\s*)[^\r\n]*/i',
            '/(ciphertext\s*[:=]\s*)[^\s,;]+/i',
            '/\bSQLSTATE(?:\[[^\]]+\])?[^\r\n]*/i',
            '/\b(?:SELECT|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b[^\r\n]*/i',
            '/(?:^|\n)\s*(?:at\s+.+|#\d+\s+.+)(?=\n|$)/m',
            '~(?:[A-Z]:\\\\|/(?:home|var|tmp|workspace)/)[^\s"\']+~i',
        ], [
            '[redacted invitation]',
            '[redacted refresh token]',
            '[redacted refresh token]',
            '[redacted jwt]',
            '[redacted token]',
            '[redacted hash]',
            '$1[redacted]',
            '$1[redacted]',
            '$1[redacted]',
            '$1[redacted]',
            '$1[redacted]',
            '$1[redacted]',
            '[redacted database error]',
            '[redacted SQL]',
            "\n[redacted stack frame]",
            '[redacted path]',
        ], $text);
    }

    /** @param array<mixed> $values
     * @return array<mixed>
     */
    private function context(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $values[$key] = '[redacted]';
            } elseif (is_string($key) && in_array(strtolower($key), ['sql', 'params', 'parameters'], true)) {
                $values[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $values[$key] = $this->redact($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->context($value);
            } elseif ($value instanceof Throwable) {
                $values[$key] = $value::class;
            }
        }

        return $values;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($normalized === strtolower($sensitiveKey)) {
                return true;
            }
        }

        return str_ends_with($normalized, 'hash') || str_ends_with($normalized, 'ciphertext');
    }
}
