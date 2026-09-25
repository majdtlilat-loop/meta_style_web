<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Packages\Application\PackagesAccess;
use App\Modules\Packages\Application\RedeemedSessions;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A manager cancels a customer's package — with a reason.
 *
 * Status becomes cancelled; every session still left is forfeited as a
 * `cancellation` row, so the history still proves the balance; nothing is
 * deleted. Refunding the money is a separate, explicit Payments refund — this
 * moves no money. Allowed after a downgrade: it is administration, not new
 * sales (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §21).
 */
final class CancelCustomerPackage
{
    public function __construct(
        private readonly PackagesAccess $access,
        private readonly RedeemedSessions $sessions,
    ) {}

    /**
     * @throws PackagesFailed
     * @throws AuthorizationException
     */
    public function __invoke(string $packageUuid, User $actingUser, string $reason, ?CarbonImmutable $now = null): CustomerPackage
    {
        $this->access->authorize($actingUser, Permission::PackageManage, __('manager_benefits.errors.may_not_cancel_packages'));

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw PackagesFailed::policy(__('manager_benefits.errors.cancel_package_reason'));
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var CustomerPackage $package */
        $package = DB::connection('tenant')->transaction(function () use ($packageUuid, $actingUser, $reason, $at): CustomerPackage {
            /** @var CustomerPackage|null $package */
            $package = CustomerPackage::query()->where('uuid', $packageUuid)->lockForUpdate()->first();

            if (! $package instanceof CustomerPackage) {
                throw new NotFoundHttpException;
            }

            $this->sessions->cancel($package, $reason, Actor::staff($actingUser), $at);

            return $package;
        });

        return $package;
    }
}
