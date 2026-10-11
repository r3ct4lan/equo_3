<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Persistence\Doctrine\Record;

use App\Debts\Domain\Debt;
use App\Debts\Domain\DebtParticipant;
use App\Debts\Domain\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'debt')]
#[ORM\Index(name: 'idx_debt_author', columns: ['created_by_id', 'is_deleted', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_debt_payer', columns: ['payer_id', 'is_deleted', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_debt_deleted', columns: ['is_deleted'])]
class DebtRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(name: 'id', type: Types::GUID)] private string $id,
        #[ORM\Column(name: 'title', type: Types::TEXT)] private string $title,
        #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)] private ?string $description,
        #[ORM\Column(name: 'total_amount', type: Types::INTEGER)] private int $totalAmount,
        #[ORM\Column(name: 'payer_id', type: Types::GUID)] private string $payerId,
        #[ORM\Column(name: 'created_by_id', type: Types::GUID)] private string $createdById,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'is_deleted', type: Types::BOOLEAN, options: ['default' => false])] private bool $isDeleted = false,
        #[ORM\Column(name: 'version', type: Types::INTEGER, options: ['default' => 1])] private int $version = 1,
    ) {
    }

    /** @param list<DebtParticipant> $participants */
    public function toDomain(array $participants): Debt
    {
        return new Debt($this->id, $this->title, $this->description, new Money($this->totalAmount), $this->payerId, $this->createdById, $this->createdAt, $participants, $this->isDeleted, $this->version);
    }
}
