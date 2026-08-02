<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'email_delivery_outbox')]
#[ORM\UniqueConstraint(name: 'uniq_email_outbox_token', columns: ['user_action_token_id'])]
#[ORM\Index(name: 'idx_email_outbox_pending', columns: ['available_at', 'created_at'], options: ['where' => "(status = 'PENDING'::text)"])]
#[ORM\Index(name: 'idx_email_outbox_status', columns: ['status'])]
#[ORM\Index(name: 'idx_email_outbox_published_at', columns: ['published_at'])]
#[ORM\Index(name: 'idx_email_outbox_sent_at', columns: ['sent_at'])]
#[ORM\Index(name: 'idx_email_outbox_failed_at', columns: ['failed_at'])]
class EmailDeliveryOutboxRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(name: 'user_action_token_id', type: Types::GUID)]
        private string $userActionTokenId,
        #[ORM\Column(name: 'recipient_email', type: Types::TEXT)]
        private string $recipientEmail,
        #[ORM\Column(name: 'template_key', type: Types::TEXT)]
        private string $templateKey,
        #[ORM\Column(name: 'encrypted_payload', type: Types::BINARY, nullable: true)]
        private ?string $encryptedPayload,
        #[ORM\Column(type: Types::TEXT, enumType: EmailDeliveryStatus::class)]
        private EmailDeliveryStatus $status,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'available_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $availableAt,
        #[ORM\Column(name: 'published_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $publishedAt,
        #[ORM\Column(name: 'sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $sentAt,
        #[ORM\Column(name: 'failed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $failedAt,
        #[ORM\Column(name: 'publish_attempts', type: Types::INTEGER)]
        private int $publishAttempts,
        #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
        private ?string $lastError,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userActionTokenId(): string
    {
        return $this->userActionTokenId;
    }

    public function recipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function templateKey(): string
    {
        return $this->templateKey;
    }

    #[Ignore]
    public function encryptedPayload(): ?string
    {
        return $this->encryptedPayload;
    }

    public function status(): EmailDeliveryStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function availableAt(): DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function sentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function failedAt(): ?DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function publishAttempts(): int
    {
        return $this->publishAttempts;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
