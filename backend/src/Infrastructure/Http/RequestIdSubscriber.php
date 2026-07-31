<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Ulid;

final class RequestIdSubscriber implements EventSubscriberInterface
{
    public const string ATTRIBUTE = '_api_request_id';
    public const string HEADER = 'X-Request-Id';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', -256],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isApiPath($event->getRequest()->getPathInfo())) {
            return;
        }

        $request = $event->getRequest();
        $providedRequestId = $request->headers->get(self::HEADER);
        $requestId = is_string($providedRequestId) && 1 === preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $providedRequestId)
            ? $providedRequestId
            : (string) new Ulid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isApiPath($event->getRequest()->getPathInfo())) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if (is_string($requestId)) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }

    private function isApiPath(string $path): bool
    {
        return '/api/' === substr($path, 0, 5);
    }
}
