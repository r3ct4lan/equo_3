<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Throwable;

#[AsMonologProcessor]
final class InvitationRedactionProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(message: $this->redact($record->message), context: $this->context($record->context), extra: $this->context($record->extra));
    }

    private function redact(string $text): string
    {
        return (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[A-Za-z0-9_-]{43}/i', '[redacted invitation]', $text);
    }

    /** @param array<mixed> $values
     * @return array<mixed>
     */
    private function context(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ['token', 'tokenhash', 'token_hash', 'sql', 'params', 'parameters'], true)) {
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
}
