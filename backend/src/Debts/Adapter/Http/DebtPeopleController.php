<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Http;

use App\Debts\Application\DebtPeopleSearch;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class DebtPeopleController
{
    public function __construct(private DebtPeopleSearch $search, private Security $security)
    {
    }

    #[Route('/api/v1/debt-people', name: 'api_v1_debt_people', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $params = $request->query->all();
        $query = $params['q'] ?? '';
        $debtId = $params['debtId'] ?? null;
        $cursor = $params['cursor'] ?? null;
        if (!is_string($query) || (null !== $debtId && !is_string($debtId)) || (null !== $cursor && !is_string($cursor)) || [] !== array_diff(array_keys($params), ['q', 'debtId', 'cursor'])) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        $user = $this->security->getUser();
        if (null === $user) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return new JsonResponse($this->search->page($user->getUserIdentifier(), $query, $debtId, $cursor), 200, ['Cache-Control' => 'no-store']);
    }
}
