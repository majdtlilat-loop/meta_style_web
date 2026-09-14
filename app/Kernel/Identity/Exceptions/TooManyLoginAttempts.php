<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Too many failed sign-in attempts against this account or from this address.
 *
 * DISTINCT FROM {@see AuthenticationFailed}, and that is a deliberate
 * disclosure: a caller must be told to stop and wait rather than being left to
 * assume their password is wrong and keep trying. It reveals nothing about
 * whether the account exists, because the bucket counts what the CALLER typed —
 * ten attempts against a number nobody has trips it exactly as fast as ten
 * against a real customer (docs/13-ROADMAP.md Phase 6 §1).
 *
 * Implements {@see HttpExceptionInterface} so a web request renders a 429
 * rather than a 500, and {@see ApiProblem} so an API request gets the standard
 * envelope with `Retry-After`.
 */
final class TooManyLoginAttempts extends RuntimeException implements ApiProblem, HttpExceptionInterface
{
    public function __construct(public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct('Too many attempts. Please wait and try again.');
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }

    public function errorCode(): ApiErrorCode
    {
        return ApiErrorCode::RateLimitExceeded;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return ['retry_after' => $this->retryAfterSeconds];
    }
}
