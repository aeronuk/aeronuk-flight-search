<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Converts the HttpException thrown by #[MapQueryString]'s validation
 * failure into the same `{"error": "..."}` JSON shape the rest of this
 * API's endpoints use, regardless of debug mode.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
class ValidationExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (! $exception instanceof HttpExceptionInterface) {
            return;
        }

        if (! $exception->getPrevious() instanceof ValidationFailedException) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => $exception->getMessage()], $exception->getStatusCode()));
    }
}
