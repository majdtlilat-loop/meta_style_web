<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Contracts\SaleFinalizationGuard;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Contracts\Container\Container;

/**
 * Asks every registered finalization guard, in registration order.
 *
 * Resolved from the container tag at call time — the same shape as
 * `SaleVoidGuards` — so Sales depends on nothing that registers here.
 */
final class SaleFinalizationGuards
{
    public const TAG = 'sales.finalization_guards';

    public function __construct(private readonly Container $container) {}

    /**
     * @throws SaleFailed
     */
    public function assertFinalizable(Sale $locked): void
    {
        foreach ($this->container->tagged(self::TAG) as $guard) {
            if ($guard instanceof SaleFinalizationGuard) {
                $guard->assertFinalizable($locked);
            }
        }
    }
}
