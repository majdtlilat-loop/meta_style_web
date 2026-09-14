<?php

declare(strict_types=1);

namespace App\Kernel\Diagnostics;

/**
 * The outcome of one production-readiness check.
 *
 * `failure` means the deployment is wrong in a way that will hurt — silently,
 * usually. `warning` means it works but will stop working as soon as the
 * deployment grows a second server. `ok` needs no action.
 */
final readonly class ReadinessCheck
{
    private function __construct(
        public string $name,
        public string $status,
        public string $detail,
        public ?string $remedy = null,
    ) {}

    public static function ok(string $name, string $detail): self
    {
        return new self($name, 'ok', $detail);
    }

    public static function warning(string $name, string $detail, string $remedy): self
    {
        return new self($name, 'warning', $detail, $remedy);
    }

    public static function failure(string $name, string $detail, string $remedy): self
    {
        return new self($name, 'failure', $detail, $remedy);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function isWarning(): bool
    {
        return $this->status === 'warning';
    }

    public function isFailure(): bool
    {
        return $this->status === 'failure';
    }
}
