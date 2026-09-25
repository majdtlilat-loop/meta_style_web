<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Contracts\SaleVoidGuard;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Every {@see SaleVoidGuard} a higher module registered, asked in turn.
 *
 * Guards are collected from the container tag {@see self::TAG}, which the
 * module that implements one adds in its service provider. Sales names no
 * implementation: with no guard registered — a center before Phase 10 — a void
 * is exactly what it was in Phase 9.
 */
final class SaleVoidGuards
{
    public const TAG = 'sales.void_guards';

    public function __construct(private readonly Container $container) {}

    /**
     * @throws SaleFailed
     */
    public function assertVoidable(Sale $locked): void
    {
        foreach ($this->container->tagged(self::TAG) as $guard) {
            if (! $guard instanceof SaleVoidGuard) {
                throw new LogicException('Only SaleVoidGuard implementations may be tagged '.self::TAG.'.');
            }

            $guard->assertVoidable($locked);
        }
    }
}
