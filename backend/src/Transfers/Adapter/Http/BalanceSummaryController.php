<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Http;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\BalanceService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BalanceSummaryController
{
    public function __construct(private BalanceService $balances, private Security $security)
    {
    }

    #[Route('/api/v1/transfers/balance-summary', name: 'api_v1_balance_summary', methods: ['GET'], priority: 10)]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (null === $user) {
            throw new UnauthorizedHttpException('Bearer');
        }
        if ([] !== $request->query->all()) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return new JsonResponse($this->balances->summary($user->getUserIdentifier()), 200, ['Cache-Control' => 'no-store']);
    }
}
