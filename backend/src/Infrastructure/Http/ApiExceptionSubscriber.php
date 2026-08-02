<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\UnexpectedPropertyException;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiJsonResponder $responder,
        private ValidationViolationNormalizer $violationNormalizer,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if ('/api/' !== substr($request->getPathInfo(), 0, 5)) {
            return;
        }

        $exception = $event->getThrowable();
        $requestId = $request->attributes->get(RequestIdSubscriber::ATTRIBUTE);

        if (!is_string($requestId)) {
            $requestId = (string) new Ulid();
            $request->attributes->set(RequestIdSubscriber::ATTRIBUTE, $requestId);
        }

        $definition = $this->classify($exception);

        if (Response::HTTP_INTERNAL_SERVER_ERROR === $definition['status']) {
            $this->logger->error('Unhandled API exception.', [
                'exception' => $exception,
                'requestId' => $requestId,
            ]);
        }

        $error = [
            'code' => $definition['code'],
            'message' => $definition['message'],
        ];

        if (null !== $definition['details']) {
            $error['details'] = $definition['details'];
        }

        $error['requestId'] = $requestId;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $event->setResponse($this->responder->respond(
            ['error' => $error],
            $definition['status'],
            $headers,
        ));
    }

    /** @return array{status: int, code: string, message: string, details: array<string, mixed>|null} */
    private function classify(Throwable $exception): array
    {
        if ($validationException = $this->find($exception, ValidationFailedException::class)) {
            return [
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Request validation failed.',
                'details' => ['violations' => $this->violationNormalizer->normalize($validationException)],
            ];
        }

        if ($this->find($exception, ExtraAttributesException::class)
            || $this->find($exception, UnexpectedPropertyException::class)) {
            return $this->definition(Response::HTTP_BAD_REQUEST, 'INVALID_REQUEST', 'Request contains unsupported fields.');
        }

        if ($this->find($exception, NotEncodableValueException::class)) {
            return $this->definition(Response::HTTP_BAD_REQUEST, 'INVALID_JSON', 'Request body must contain valid JSON.');
        }

        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                Response::HTTP_BAD_REQUEST => $this->definition(Response::HTTP_BAD_REQUEST, 'INVALID_REQUEST', 'Request is invalid.'),
                Response::HTTP_UNAUTHORIZED => $this->definition(Response::HTTP_UNAUTHORIZED, 'AUTHENTICATION_REQUIRED', 'Authentication is required.'),
                Response::HTTP_FORBIDDEN => $this->definition(Response::HTTP_FORBIDDEN, 'FORBIDDEN', 'Operation is forbidden.'),
                Response::HTTP_NOT_FOUND => $this->definition(Response::HTTP_NOT_FOUND, 'RESOURCE_NOT_FOUND', 'Resource was not found.'),
                Response::HTTP_METHOD_NOT_ALLOWED => $this->definition(Response::HTTP_METHOD_NOT_ALLOWED, 'METHOD_NOT_ALLOWED', 'HTTP method is not allowed for this resource.'),
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE => $this->definition(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.'),
                Response::HTTP_UNPROCESSABLE_ENTITY => $this->definition(Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR', 'Request validation failed.'),
                Response::HTTP_PRECONDITION_REQUIRED => $this->definition(Response::HTTP_PRECONDITION_REQUIRED, 'PRECONDITION_REQUIRED', 'If-Match header is required.'),
                Response::HTTP_TOO_MANY_REQUESTS => $this->definition(Response::HTTP_TOO_MANY_REQUESTS, 'RATE_LIMIT_EXCEEDED', 'Rate limit exceeded.'),
                default => $this->definition(Response::HTTP_INTERNAL_SERVER_ERROR, 'INTERNAL_SERVER_ERROR', 'An unexpected error occurred.'),
            };
        }

        return $this->definition(Response::HTTP_INTERNAL_SERVER_ERROR, 'INTERNAL_SERVER_ERROR', 'An unexpected error occurred.');
    }

    /** @return array{status: int, code: string, message: string, details: null} */
    private function definition(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'details' => null,
        ];
    }

    /** @template T of Throwable
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(Throwable $exception, string $class): ?Throwable
    {
        do {
            if ($exception instanceof $class) {
                return $exception;
            }
        } while ($exception = $exception->getPrevious());

        return null;
    }
}
