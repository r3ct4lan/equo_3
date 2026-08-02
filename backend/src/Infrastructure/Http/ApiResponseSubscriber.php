<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(private ApiJsonResponder $responder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::VIEW => ['onKernelView', 64]];
    }

    public function onKernelView(ViewEvent $event): void
    {
        if ('/api/' !== substr($event->getRequest()->getPathInfo(), 0, 5)) {
            return;
        }

        $result = $event->getControllerResult();

        if (!is_array($result) && !is_object($result)) {
            return;
        }

        $status = $event->getRequest()->attributes->getInt('_api_status', 200);
        $event->setResponse($this->responder->respond($result, $status));
    }
}
