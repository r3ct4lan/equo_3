<?php

declare(strict_types=1);

namespace App\Debts\Domain;

use InvalidArgumentException;

final readonly class DebtUpdate
{
    public string $title;

    /** @param list<string> $participantIds */
    public function __construct(string $title, public ?string $description, public Money $totalAmount, public array $participantIds, public bool $includePayerInSplit)
    {
        if ('' === trim($title)) {
            throw new InvalidArgumentException('Debt title is required.');
        }
        $this->title = trim($title);
    }
}
