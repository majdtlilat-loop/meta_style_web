<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An operational refusal: a stage that cannot start, a transition that is not
 * on the map, a resource that is not free.
 *
 * The Journey counterpart of `Booking\Domain\Exceptions\BookingFailed`, and
 * built the same way: it implements {@see ApiProblem} so the API renders the
 * standard envelope, and {@see HttpExceptionInterface} so a web request gets a
 * real status rather than a 500.
 *
 * Messages here are for STAFF, not customers, which is why they can be specific.
 * "Room 3 is already in use at that time" is exactly what somebody at the desk
 * needs; the reticence the public booking flow practises exists because a guest
 * must not be able to read the center's book, and no guest reaches this module
 * (docs/13-ROADMAP.md Phase 7 §46).
 */
final class JourneyFailed extends RuntimeException implements ApiProblem, HttpExceptionInterface
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        string $message,
        private readonly ApiErrorCode $apiCode,
        private readonly int $status,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function invalidTransition(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::JourneyInvalidTransition, 422, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function policy(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::JourneyPolicyViolation, 422, $details);
    }

    /**
     * A room, chair or device that is not free.
     *
     * @param  array<string, mixed>  $details
     */
    public static function resourceUnavailable(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::JourneyResourceUnavailable, 409, $details);
    }

    public function getStatusCode(): int
    {
        return $this->status;
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
