<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Closure;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class JsonContentTypeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onKernelController', 128]];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $method = $this->controllerMethod($controller);

        if (null === $method || !$this->mapsRequestPayload($method)) {
            return;
        }

        $contentType = $event->getRequest()->headers->get('Content-Type');
        $mediaType = strtolower(trim(explode(';', $contentType ?? '', 2)[0]));

        if ('application/json' !== $mediaType) {
            throw new UnsupportedMediaTypeHttpException('Content-Type must be application/json.');
        }
    }

    /** @param callable(): mixed $controller */
    private function controllerMethod(callable $controller): ?ReflectionMethod
    {
        if (is_array($controller)) {
            return new ReflectionMethod($controller[0], $controller[1]);
        }

        if (is_object($controller) && !$controller instanceof Closure) {
            return new ReflectionMethod($controller, '__invoke');
        }

        if (is_string($controller) && str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);

            return new ReflectionMethod($class, $method);
        }

        return null;
    }

    private function mapsRequestPayload(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            if ([] !== $parameter->getAttributes(MapRequestPayload::class)) {
                return true;
            }
        }

        return false;
    }
}
