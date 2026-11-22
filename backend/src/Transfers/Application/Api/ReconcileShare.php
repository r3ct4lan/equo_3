<?php

declare(strict_types=1);

namespace App\Transfers\Application\Api;

interface ReconcileShare
{
    public function reconcile(string $shareId): void;
}
