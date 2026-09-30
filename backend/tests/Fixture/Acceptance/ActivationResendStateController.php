<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Acceptance;

use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class ActivationResendStateController
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{
     *     exists: bool,
     *     active: bool,
     *     tokenCount: int,
     *     unfinishedTokenCount: int,
     *     invalidatedTokenCount: int,
     *     usedTokenCount: int,
     *     outboxCount: int
     * }
     */
    public function __invoke(Request $request): array
    {
        $email = $request->toArray()['email'] ?? null;

        if (!is_string($email)) {
            throw new BadRequestHttpException('A test email is required.');
        }

        $user = $this->connection->fetchAssociative(
            'SELECT id, is_active FROM app_user WHERE email = :email',
            ['email' => strtolower(trim($email))],
        );

        if (false === $user) {
            return $this->emptyState();
        }

        $userId = $user['id'];

        if (!is_string($userId)) {
            throw new BadRequestHttpException('The test user state is invalid.');
        }

        $tokens = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(*) AS token_count,
                    COUNT(*) FILTER (
                        WHERE used_at IS NULL AND invalidated_at IS NULL
                    ) AS unfinished_token_count,
                    COUNT(*) FILTER (WHERE invalidated_at IS NOT NULL) AS invalidated_token_count,
                    COUNT(*) FILTER (WHERE used_at IS NOT NULL) AS used_token_count
                FROM user_action_token
                WHERE user_id = :user_id AND purpose = :purpose
                SQL,
            [
                'user_id' => $userId,
                'purpose' => UserActionTokenPurpose::ActivateAccount->value,
            ],
        );
        $outboxCount = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM email_delivery_outbox outbox
                INNER JOIN user_action_token token ON token.id = outbox.user_action_token_id
                WHERE token.user_id = :user_id AND token.purpose = :purpose
                SQL,
            [
                'user_id' => $userId,
                'purpose' => UserActionTokenPurpose::ActivateAccount->value,
            ],
        );

        return [
            'exists' => true,
            'active' => filter_var($user['is_active'], FILTER_VALIDATE_BOOL),
            'tokenCount' => (int) ($tokens['token_count'] ?? 0),
            'unfinishedTokenCount' => (int) ($tokens['unfinished_token_count'] ?? 0),
            'invalidatedTokenCount' => (int) ($tokens['invalidated_token_count'] ?? 0),
            'usedTokenCount' => (int) ($tokens['used_token_count'] ?? 0),
            'outboxCount' => (int) $outboxCount,
        ];
    }

    /** @return array{exists: false, active: false, tokenCount: 0, unfinishedTokenCount: 0, invalidatedTokenCount: 0, usedTokenCount: 0, outboxCount: 0} */
    private function emptyState(): array
    {
        return [
            'exists' => false,
            'active' => false,
            'tokenCount' => 0,
            'unfinishedTokenCount' => 0,
            'invalidatedTokenCount' => 0,
            'usedTokenCount' => 0,
            'outboxCount' => 0,
        ];
    }
}
