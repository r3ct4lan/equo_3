<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Domain\User\User;
use App\IdentityAccess\Domain\User\VerifiedTelegramIdentity;
use JsonException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TelegramMiniAppVerifier
{
    public const int MAX_DATA_BYTES = 8192;

    public function __construct(
        private ClockPort $clock,
        #[Autowire(env: 'EQUO_TELEGRAM_BOT_TOKEN')]
        #[SensitiveParameter]
        private string $botToken,
    ) {
    }

    public function verify(#[SensitiveParameter] string $initData): VerifiedTelegramIdentity
    {
        if ('' === $this->botToken) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }

        $fields = $this->parse($initData);
        $hash = $fields['hash'] ?? '';
        unset($fields['hash']);
        ksort($fields, SORT_STRING);
        $rows = [];
        foreach ($fields as $key => $value) {
            $rows[] = $key.'='.$value;
        }

        $secret = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $timestamp = $fields['auth_date'] ?? '';
        $now = $this->clock->now()->getTimestamp();
        if (1 !== preg_match('/\A[0-9a-f]{64}\z/D', $hash)
            || !hash_equals(hash_hmac('sha256', implode("\n", $rows), $secret), $hash)
            || 1 !== preg_match('/\A[0-9]{1,10}\z/D', $timestamp)
            || (int) $timestamp < $now - 300 || (int) $timestamp > $now + 30
        ) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
        }

        try {
            $user = json_decode($fields['user'] ?? '', true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
        }

        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if (!is_array($user) || !is_int($id) || $id < 1 || $id > User::MAX_TELEGRAM_ID) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
        }

        $firstName = is_string($user['first_name'] ?? null) ? $user['first_name'] : '';
        $lastName = is_string($user['last_name'] ?? null) ? $user['last_name'] : '';
        $name = trim(str_replace(["\r", "\n", "\0"], ' ', $firstName.' '.$lastName));
        $language = is_string($user['language_code'] ?? null) ? strtolower($user['language_code']) : '';

        return new VerifiedTelegramIdentity(
            $id,
            '' === $name ? 'Telegram user' : mb_substr($name, 0, 100),
            'ru' === explode('-', $language, 2)[0] ? 'ru' : 'en',
            $fields['start_param'] ?? null,
        );
    }

    /** @return array<string, string> */
    private function parse(#[SensitiveParameter] string $data): array
    {
        if ('' === $data || strlen($data) > self::MAX_DATA_BYTES) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
        }

        $fields = [];
        foreach (explode('&', $data) as $part) {
            $parts = explode('=', $part, 2);
            if (2 !== count($parts) || 1 === preg_match('/%(?![0-9a-f]{2})/i', $part)) {
                throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
            }

            [$key, $value] = array_map(urldecode(...), $parts);
            if (1 !== preg_match('/\A[a-z_][a-z0-9_]{0,63}\z/D', $key)
                || isset($fields[$key]) || 1 === preg_match('/[\r\n\x00]/', $value)
            ) {
                throw new ApplicationFailure(ApplicationFailureCode::TelegramAuthenticationFailed);
            }

            $fields[$key] = $value;
        }

        return $fields;
    }
}
