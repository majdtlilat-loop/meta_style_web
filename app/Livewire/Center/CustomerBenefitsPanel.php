<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Customers\Concerns\FormatsLocalDates;
use App\Modules\Loyalty\Application\Actions\AdjustPoints;
use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyPresenter;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Memberships\Application\Actions\CancelCustomerMembership;
use App\Modules\Memberships\Application\MembershipsPresenter;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Packages\Application\Actions\CancelCustomerPackage;
use App\Modules\Packages\Application\PackagesPresenter;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One customer's points, memberships and packages, on the customer page.
 *
 * Its own component, embedded, so the Customers screen imports none of the
 * benefit modules. Reading is read-only: history stays visible after a
 * downgrade, and nothing here repairs or activates anything. A hand adjustment
 * needs `loyalty.adjust`, a reason AND the `loyalty` entitlement (it is new
 * activity); cancelling needs the manage permission and a reason, moves no
 * money, and is never blocked by a downgrade (docs/21 §§19, 21, 22, 27).
 *
 * Each module is read on its own: a failure in one never blanks the others.
 * A module the center does not own shows the compact plan notice: over its
 * history when there is some (read-only), alone when there never was any —
 * then nothing is read (the Manager downgrade rule).
 */
final class CustomerBenefitsPanel extends Component
{
    use FormatsLocalDates;
    use RequiresFeature;

    #[Locked]
    public string $customer = '';

    /** `all`, `loyalty` (points only) or `plans` (memberships and packages). */
    #[Locked]
    public string $section = 'all';

    public string $direction = 'in';

    public string $points = '';

    public string $reason = '';

    /** `membership:{uuid}` or `package:{uuid}` — the card being cancelled. */
    public string $cancelling = '';

    public string $cancelReason = '';

    public string $error = '';

    public string $saved = '';

    public function adjust(AdjustPoints $adjust): void
    {
        $this->guard(function () use ($adjust): void {
            $adjust($this->customer, $this->user(), PointsDirection::from($this->direction === 'out' ? 'out' : 'in'), (int) $this->points, $this->reason);

            $this->reset(['points', 'reason']);
            $this->saved = __('manager_benefits.panel.adjusted');
        });
    }

    public function startCancel(string $key): void
    {
        $this->reset(['error', 'saved', 'cancelReason']);
        $this->cancelling = $key;
    }

    public function stopCancel(): void
    {
        $this->reset(['cancelling', 'cancelReason']);
    }

    /**
     * `cancelling` is `membership:{uuid}` or `package:{uuid}`.
     */
    public function cancel(CancelCustomerMembership $membership, CancelCustomerPackage $package): void
    {
        $this->guard(function () use ($membership, $package): void {
            [$kind, $uuid] = array_pad(explode(':', $this->cancelling, 2), 2, '');

            match ($kind) {
                'membership' => $membership($uuid, $this->user(), $this->cancelReason),
                'package' => $package($uuid, $this->user(), $this->cancelReason),
                default => throw new NotFoundHttpException,
            };

            $this->reset(['cancelling', 'cancelReason']);
            $this->saved = __('manager_benefits.panel.cancelled');
        });
    }

    public function render(
        LoyaltyQuery $loyalty,
        LoyaltyPresenter $loyaltyPresenter,
        LoyaltyAccess $loyaltyAccess,
        MembershipsQuery $memberships,
        MembershipsPresenter $membershipsPresenter,
        PackagesQuery $packages,
        PackagesPresenter $packagesPresenter,
    ): View {
        $user = $this->user();
        $showLoyalty = $this->section !== 'plans';
        $showPlans = $this->section !== 'loyalty';

        $loyaltyView = null;
        $membershipsView = null;
        $packagesView = null;
        $locks = [];

        if ($showLoyalty && $user->hasPermission(Permission::LoyaltyView) && $this->readable('loyalty', $loyalty->hasHistory(...), $locks)) {
            $loyaltyView = $this->read(fn (): array => $this->loyalty(
                $loyaltyPresenter->forStaff($loyalty->forCustomer($this->customer, $user)['account'], $loyalty->currentProgram()),
            ));
        }

        if ($showPlans && $user->hasPermission(Permission::MembershipView) && $this->readable('memberships', $memberships->hasHistory(...), $locks)) {
            $membershipsView = $this->read(fn (): array => array_map(
                fn (array $row): array => $this->membership($row),
                $membershipsPresenter->forStaff($memberships->forCustomer($this->customer, $user)['memberships']),
            ));
        }

        if ($showPlans && $user->hasPermission(Permission::PackageView) && $this->readable('packages', $packages->hasHistory(...), $locks)) {
            $packagesView = $this->read(fn (): array => array_map(
                fn (array $row): array => $this->package($row),
                $packagesPresenter->forStaff($packages->forCustomer($this->customer, $user)['packages']),
            ));
        }

        return view('livewire.center.customer-benefits-panel', [
            'showLoyalty' => $showLoyalty,
            'showPlans' => $showPlans,
            'loyalty' => $loyaltyView,
            'memberships' => $membershipsView,
            'packages' => $packagesView,
            'locks' => $locks,
            'loyaltyOn' => $loyaltyAccess->enabled(),
            'canAdjust' => $user->hasPermission(Permission::LoyaltyAdjust) && $loyaltyAccess->enabled(),
            'canCancelMembership' => $user->hasPermission(Permission::MembershipManage),
            'canCancelPackage' => $user->hasPermission(Permission::PackageManage),
            'nothingVisible' => ! (($showLoyalty && $user->hasPermission(Permission::LoyaltyView))
                || ($showPlans && ($user->hasPermission(Permission::MembershipView) || $user->hasPermission(Permission::PackageView)))),
        ]);
    }

    /**
     * Whether a module's data may be read here. Owned: yes. Not owned: only
     * when the center has history in it — and either way the lock is noted
     * for the compact notice.
     *
     * @param  callable(): bool  $hasHistory
     * @param  array<string, array{offer: array<string, mixed>, history: bool}>  $locks
     */
    private function readable(string $feature, callable $hasHistory, array &$locks): bool
    {
        $offer = $this->lockedFeature($feature);

        if ($offer === null) {
            return true;
        }

        $history = $hasHistory();
        $locks[$feature] = ['offer' => $offer, 'history' => $history];

        return $history;
    }

    /**
     * One module's read; a refusal or a vanished customer leaves that module
     * empty and the others untouched.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function read(callable $read): mixed
    {
        try {
            return $read();
        } catch (AuthorizationException|NotFoundHttpException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private function loyalty(array $shape): array
    {
        /** @var list<array<string, mixed>> $recent */
        $recent = $shape['recent'];

        $shape['recent'] = array_map(fn (array $row): array => $row + [
            'kind_label' => __('manager_benefits.kinds.'.$row['kind']),
            'when' => $this->localDateTime((string) $row['occurred_at']),
            'signed' => ($row['direction'] === 'in' ? '+' : '−').number_format((int) $row['points']),
        ], $recent);

        return $shape;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function membership(array $row): array
    {
        return $row + [
            'state_label' => __('manager_benefits.states.'.$row['state']),
            'first_day_label' => $this->day((string) $row['first_day']),
            'last_day_label' => $this->day((string) $row['last_day']),
            'cancellable' => in_array($row['state'], ['active', 'upcoming'], true),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function package(array $row): array
    {
        return $row + [
            'state_label' => __('manager_benefits.states.'.$row['state']),
            'last_day_label' => $this->day((string) $row['last_day']),
            'cancellable' => $row['state'] === 'active',
        ];
    }

    /** A branch-local calendar day (`Y-m-d`), in the viewer's language. */
    private function day(string $date): string
    {
        return CarbonImmutable::parse($date)->locale(app()->getLocale())->isoFormat('D MMM YYYY');
    }

    private function guard(callable $work): void
    {
        $this->reset(['error', 'saved']);

        try {
            $work();
        } catch (LoyaltyFailed|MembershipsFailed|PackagesFailed|AuthorizationException|EntitlementRequired $failure) {
            $this->error = $failure->getMessage();
        } catch (NotFoundHttpException) {
            $this->error = __('manager_benefits.panel.not_found');
        }
    }

    private function user(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
