<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\UserInterface\REST;

use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

class ValidationExceptionListenerTest extends TestCase
{
    private function makeEvent(Throwable $throwable): ExceptionEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    #[Test]
    public function replacesResponseForValidationFailedHttpException(): void
    {
        $validationFailed = new ValidationFailedException(null, new ConstraintViolationList());
        $httpException    = new HttpException(400, 'origin is required', $validationFailed);
        $event            = $this->makeEvent($httpException);

        (new ValidationExceptionListener())($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"error": "origin is required"}',
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function leavesHttpExceptionWithOtherPreviousUntouched(): void
    {
        $httpException = new HttpException(400, 'bad request', new RuntimeException('some other failure'));
        $event         = $this->makeEvent($httpException);

        (new ValidationExceptionListener())($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function leavesNonHttpExceptionUntouched(): void
    {
        $event = $this->makeEvent(new Exception('boom'));

        (new ValidationExceptionListener())($event);

        self::assertNull($event->getResponse());
    }
}
