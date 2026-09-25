<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A review operation the rules refuse.
 *
 * ## The public surface says less than this does
 *
 * These codes are for STAFF and for a signed-in customer, who are already
 * entitled to know which visit they are looking at. The unauthenticated review
 * page maps an unknown, revoked, expired or already-used token to ONE generic
 * 404: telling a stranger that a token "has already been used" confirms that it
 * existed, which is a visit, which is a customer (docs/22-REVIEWS.md §§17, 21).
 */
final class ReviewFailed extends RuntimeException implements ApiProblem, HttpExceptionInterface
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        string $message,
        private readonly ApiErrorCode $apiCode,
        private readonly int $status = 422,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The visit cannot be reviewed at all — not completed, nothing performed.
     *
     * @param  array<string, mixed>  $details
     */
    public static function notEligible(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::ReviewNotEligible, 422, $details);
    }

    /**
     * The capability does not open anything. Deliberately a 404, and
     * deliberately the same answer for unknown, revoked and expired.
     */
    public static function invalidToken(): self
    {
        return new self('That review link is not valid.', ApiErrorCode::ReviewInvalidToken, 404);
    }

    /**
     * The visit already has its review. Never shown to a stranger.
     */
    public static function alreadySubmitted(): self
    {
        return new self('That visit has already been reviewed.', ApiErrorCode::ReviewAlreadySubmitted, 409);
    }

    /**
     * A score out of range, or a target the visit does not contain.
     *
     * @param  array<string, mixed>  $details
     */
    public static function invalidRating(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::ReviewRatingInvalid, 422, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function policy(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::ReviewPolicyViolation, 422, $details);
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
