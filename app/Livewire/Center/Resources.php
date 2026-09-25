<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The physical side of a center: chairs, rooms and devices, the types they
 * belong to, what each service needs, and when people are unavailable.
 *
 * A thin shell. Each tab is its own component under `Resources\`, re-checks
 * its own permission, and saves only through the Action the API calls — where
 * the branch lock, the capacity floor, the scope check and the audit entry live
 * (docs/04-MODULE-BOUNDARIES.md, docs/16-JOURNEY-RESOURCES.md).
 */
#[Layout('components.layouts.app')]
final class Resources extends Component
{
    public const TABS = ['resources', 'types', 'requirements', 'blocks'];

    #[Url(except: 'resources')]
    public string $tab = 'resources';

    /**
     * @throws AuthorizationException
     */
    public function mount(): void
    {
        if (! $this->user()->hasPermission(Permission::ResourceView)) {
            throw new AuthorizationException(__('manager_staff.errors.resource_view_denied'));
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'resources';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'resources';
    }

    public function render(): mixed
    {
        return view('livewire.center.resources', [
            'tabs' => self::TABS,
        ])->title(__('ui.manager_nav.items.resources'));
    }

    private function user(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
