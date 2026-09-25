<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A payment, refund or gateway operation the rules refuse.
 *
 * Messages are written for staff at a desk and never carry a provider's raw
 * error, a credential or anything about another center. The public payment
 * surface replaces them with a generic answer (docs/19-PAYMENTS.md §84).
 */
final class PaymentFailed extends RuntimeException implements ApiProblem, HttpExceptionInterface
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
        return new self($message, ApiErrorCode::PaymentsInvalidTransition, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function policy(string $message, array $details = []): self
    {
        return new self($message, ApiErrorCode::PaymentsPolicyViolation, $details);
    }

    /** The provider could not be reached or did not answer usably. Worth retrying. */
    public static function providerUnavailable(string $message = 'The payment provider did not respond. Try again shortly.'): self
    {
        return new self($message, ApiErrorCode::PaymentsProviderUnavailable);
    }

    /** The provider has no such capability, or no verified adapter exists. */
    public static function unsupported(string $message): self
    {
        return new self($message, ApiErrorCode::PaymentsProviderUnsupported);
    }

    public function getStatusCode(): int
    {
        return $this->apiCode->httpStatus();
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
