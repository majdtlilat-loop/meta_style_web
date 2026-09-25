<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Identity\Models\User;
use App\View\Manager\PlanOverview;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The center's plan: subscription, what it includes, allowances, and a
 * read-only comparison with the public plans.
 *
 * ## `settings.view`, like Usage
 *
 * The plan is a commercial read about the center as a whole — exactly what
 * `settings.view` already means (see {@see Usage}). A person without it gets a
 * clear refusal state, not the page.
 *
 * ## Read-only
 *
 * No plan change happens here: that is Meta Style's (Super Admin). A center
 * that wants another plan asks through platform support, with the subject
 * prefilled.
 */
#[Layout('components.layouts.app')]
final class Plan extends Component
{
    /** A feature to highlight in the comparison (from a locked item or prompt). */
    #[Url(except: '')]
    public string $feature = '';

    #[Url(except: 'monthly')]
    public string $cycle = 'monthly';

    public function setCycle(string $cycle): void
    {
        $this->cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
    }

    public function render(PlanOverview $overview, EntitlementCatalog $catalog): View
    {
        /** @var User $user */
        $user = auth()->user();
        $this->cycle = $this->cycle === 'yearly' ? 'yearly' : 'monthly';
        $feature = $catalog->has($this->feature) ? $this->feature : '';

        if (! $user->hasPermission(Permission::SettingsView)) {
            return view('livewire.center.plan', ['allowed' => false, 'plan' => null])
                ->title(__('manager_plan.title'));
        }

        return view('livewire.center.plan', [
            'allowed' => true,
            'plan' => $overview->build($user, $this->cycle, $feature, app()->getLocale()),
        ])->title(__('manager_plan.title'));
    }
}
