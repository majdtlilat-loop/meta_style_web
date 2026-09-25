<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A notification operation the rules refuse.
 *
 * There is exactly one refusal worth a code: reaching for somebody else's
 * inbox. Everything else a caller can get wrong here is validation, and a
 * notification that could not be CREATED is never an exception the source
 * operation sees — it is reported and repaired (docs/23-NOTIFICATIONS.md §11).
 */
final class NotificationsFailed extends RuntimeException implements ApiProblem, HttpExceptionInterface
{
    private function __construct(string $message, private readonly ApiErrorCode $apiCode, private readonly int $status)
    {
        parent::__construct($message);
    }

    /**
     * Another person's notification. A 404, not a 403: "you may not read this"
     * still confirms it exists (docs/08-AUDIT-SECURITY.md).
     */
    public static function notYours(): self
    {
        return new self('That notification does not exist.', ApiErrorCode::NotFound, 404);
    }

    public static function policy(string $message): self
    {
        return new self($message, ApiErrorCode::NotificationPolicyViolation, 422);
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
        return [];
    }
}
