<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Persistence\Doctrine\Record;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'receipt_upload')]
#[ORM\UniqueConstraint(name: 'uniq_receipt_request', columns: ['owner_id', 'request_key'])]
#[ORM\UniqueConstraint(name: 'uniq_receipt_debt', columns: ['debt_id'])]
#[ORM\Index(name: 'idx_receipt_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_receipt_owner', columns: ['owner_id'])]
class ReceiptUploadRecord
{
    /** @param array<int,string> $photos
     * @param array<string,mixed>|null $draft
     */
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::GUID)] private string $id,
        #[ORM\Column(type: Types::GUID)] private string $ownerId,
        #[ORM\Column(type: Types::GUID)] private string $requestKey,
        #[ORM\Column(length: 64)] private string $fingerprint,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::JSONB)] private array $photos,
        #[ORM\Column(type: Types::GUID, nullable: true)] private ?string $debtId = null,
        #[ORM\Column(type: Types::GUID, nullable: true)] private ?string $attempt = null,
        #[ORM\Column(length: 12, options: ['default' => 'pending'])] private string $state = 'pending',
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)] private ?DateTimeImmutable $startedAt = null,
        #[ORM\Column(type: Types::JSONB, nullable: true)] private ?array $draft = null,
    ) {
    }
}
