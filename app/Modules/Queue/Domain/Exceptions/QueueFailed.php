<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A queue operation the rules refuse.
 *
 * Built exactly like `JourneyFailed` and `BookingFailed`: it implements
 * {@see ApiProblem} so the API renders the standard envelope, and
 * {@see HttpExceptionInterface} so a web request gets a real status rather than
 * a 500.
 *
 * Two shapes, and both messages are written to be read at a reception desk. "A
 * ticket that is completed cannot be called" tells somebody what happened;
 * "invalid transition" does not. Staff-facing, like the rest of this module —
 * there is no customer queue surface beyond the public display, and that one
 * shows numbers (docs/17-QUEUE.md §14).
 */
final class QueueFailed extends RuntimeException implements ApiProblem, HttpExceptionInterface
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        string $message,
        private readonly ApiErrorCode $apiCode,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function invalidTransition(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::QueueInvalidTransition, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function policy(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::QueuePolicyViolation, $details);
    }

    /**
     * 422 for both.
     *
     * Unlike a booking conflict, a queue refusal is never a race with somebody
     * else taking a slot — it is a statement about a ticket whose state the
     * caller can read, and a retry would be refused just as firmly.
     */
    public function getStatusCode(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    public function errorCode(): ApiErrorCode
    {
        return $this->apiCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return $this->details;
    }
}
