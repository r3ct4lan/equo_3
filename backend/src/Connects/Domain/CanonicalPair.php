<?php

declare(strict_types=1);

namespace App\Connects\Domain;

use InvalidArgumentException;

final readonly class CanonicalPair
{
    public string $first;
    public string $second;

    public function __construct(string $a, string $b)
    {
        $a = strtolower($a);
        $b = strtolower($b);
        foreach ([$a, $b] as $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
                throw new InvalidArgumentException('User identifier is invalid.');
            }
        }
        if ($a === $b) {
            throw new InvalidArgumentException('Connect requires different users.');
        }
        $this->first = min($a, $b);
        $this->second = max($a, $b);
    }
}
