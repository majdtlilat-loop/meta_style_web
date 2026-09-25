<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An expense, ledger or drawer-count operation the rules refuse.
 */
final class FinanceFailed extends RuntimeException implements ApiProblem, HttpExceptionInterface
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
        return new self($message, ApiErrorCode::FinanceInvalidTransition, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function policy(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::FinancePolicyViolation, $details);
    }

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
