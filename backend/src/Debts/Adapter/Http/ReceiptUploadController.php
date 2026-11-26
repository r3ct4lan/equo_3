<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Http;

use App\Debts\Adapter\Messaging\ReadReceipt;
use App\Debts\Application\CreateDebtCommand;
use App\Debts\Application\Port\ReceiptStorage;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Infrastructure\Application\Api\RequestLimiter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/debts/receipt-uploads')]
final readonly class ReceiptUploadController
{
    public function __construct(private ReceiptStorage $storage, private Security $security, private MessageBusInterface $bus, private RequestLimiter $limiter)
    {
    }

    #[Route('', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $owner = $this->owner();
        $key = $this->key($request);
        $photos = $request->toArray()['photos'] ?? null;
        if (!is_array($photos) || !array_is_list($photos) || [] === $photos || count($photos) > 3) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        foreach ($photos as $photo) {
            if (!is_string($photo) || strlen($photo) > 5592432 || !preg_match('~^data:(image/(?:jpeg|png|webp));base64,~D', $photo, $match)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            $bytes = base64_decode(substr($photo, strlen($match[0])), true);
            $info = false === $bytes ? false : @getimagesizefromstring($bytes);
            if (false === $info || $info['mime'] !== $match[1] || $info[0] * $info[1] > 50000000 || strlen((string) $bytes) > 4194304) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        $this->limit($owner);
        $id = $this->storage->prepare($owner, $key, $photos);

        return $this->queue($id, $owner, $key);
    }

    #[Route('/{id}/retry', methods: ['POST'])]
    public function retry(string $id, Request $request): JsonResponse
    {
        $owner = $this->owner();
        $key = $this->key($request);
        $this->checkId($id);
        $this->limit($owner);
        $this->storage->retry($id, $owner, $key);

        return $this->queue($id, $owner, $key);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $this->checkId($id);

        return new JsonResponse($this->storage->status($id, $this->owner()), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/{id}/photos/{number}', requirements: ['number' => '[1-3]'], methods: ['GET'])]
    public function photo(string $id, int $number): JsonResponse
    {
        $this->checkId($id);
        $images = $this->storage->draftPhotos($id, $this->owner());

        return new JsonResponse(['image' => $images[$number - 1] ?? throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound)], headers: ['Cache-Control' => 'no-store']);
    }

    private function queue(string $id, string $owner, string $key): JsonResponse
    {
        $status = $this->storage->status($id, $owner);
        if ('pending' === $status['state']) {
            $this->bus->dispatch(new ReadReceipt($id, $owner, $key));
        }

        return new JsonResponse($status, in_array($status['state'], ['pending', 'reading'], true) ? 202 : 200, ['Cache-Control' => 'no-store']);
    }

    private function limit(string $owner): void
    {
        $delay = $this->limiter->consume(['receipt.upload.'.$owner => 12]);
        if (null !== $delay) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $delay);
        }
    }

    private function owner(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? throw new UnauthorizedHttpException('Bearer');
    }

    private function checkId(string $id): void
    {
        if (!CreateDebtCommand::isUuid($id)) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
    }

    private function key(Request $request): string
    {
        $key = $request->headers->get('Idempotency-Key') ?? throw new ApplicationFailure(ApplicationFailureCode::IdempotencyKeyRequired);

        return CreateDebtCommand::isUuid($key) ? strtolower($key) : throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
    }
}
