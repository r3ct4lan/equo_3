<?php

declare(strict_types=1);

namespace App\Currency\Adapter;

use App\Currency\Application\Api\ExchangeMarket;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CurrencyController
{
    public function __construct(private ExchangeMarket $market)
    {
    }

    #[Route('/api/v1/currencies', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        return new JsonResponse($this->market->currencies());
    }

    #[Route('/api/v1/currencies/{from}/{to}', requirements: ['from' => '[A-Z]{3}', 'to' => '[A-Z]{3}'], methods: ['GET'])]
    public function quote(string $from, string $to): JsonResponse
    {
        $this->market->requireCurrency($from);
        $this->market->requireCurrency($to);
        $quote = BigRational::of($this->market->quote($from, $to))->toScale(18, RoundingMode::HalfUp);

        return new JsonResponse(['rate' => (string) $quote->strippedOfTrailingZeros()]);
    }
}
