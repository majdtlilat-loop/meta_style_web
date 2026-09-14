<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Branches\Domain\Models\Branch;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The authenticated shell.
 *
 * Enough to confirm the Phase 3 flows work end to end — who am I, which center,
 * what does it own, how long is the trial. The real dashboard is later frontend
 * work and is deliberately not started here.
 */
#[Layout('components.layouts.app')]
final class Dashboard extends Component
{
    public function render(TenantContext $tenants, Entitlements $entitlements): mixed
    {
        $tenant = $tenants->require();

        /** @var User $user */
        $user = auth()->user();

        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->first();

        return view('livewire.center.dashboard', [
            'tenant' => $tenant,
            'user' => $user,
            'permissionCount' => count($user->permissions()),
            'branches' => Branch::query()->orderBy('id')->get(),
            'entitlements' => $entitlements->all(),
            'accessLevel' => $entitlements->accessLevel()->value,
            'subscription' => $subscription,
            'trialDaysLeft' => $subscription?->trialDaysRemaining(),
        ]);
    }
}
