<?php

declare(strict_types=1);

namespace App\Livewire\Center\Queue;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Modules\Queue\Application\Actions\SaveServicePoint;
use App\Modules\Queue\Application\QueueSetupQuery;
use App\Modules\ServiceJourney\Application\VisitOptions;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Service desks: the places a ticket is called TO ("R1 — Reception",
 * "L2 — Laser room"). docs/17-QUEUE.md §8.
 *
 * A destination is not a resource. Linking one to a room assigns nothing when
 * somebody is called there — capacity is Journey's, under the branch lock.
 * Archive, never delete: tickets already sent to a desk keep pointing at it.
 */
final class ServicePoints extends Component
{
    use RunsDeskActions;

    public bool $archived = false;

    /** '' (closed), 'new', or the uuid being edited. */
    public string $editing = '';

    public string $branch = '';

    /** @var array<string, string> */
    public array $names = [];

    public string $code = '';

    public string $prefix = '';

    public string $department = '';

    public string $resource = '';

    public bool $active = true;

    public int $sort = 0;

    public function mount(): void
    {
        // The same permission SaveServicePoint requires; the list is theirs.
        abort_unless($this->viewer()->hasPermission(Permission::QueueDisplayManage), 403);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->editing = 'new';
        $this->branch = app(VisitOptions::class)->branches($this->viewer())[0]['uuid'] ?? '';
    }

    public function edit(string $uuid, QueueSetupQuery $setup): void
    {
        $this->resetForm();

        $this->attempt(function () use ($uuid, $setup): void {
            $point = $setup->point($this->viewer(), $uuid);
            $point->loadMissing(['branch', 'department', 'resource']);

            $this->editing = $point->uuid;
            $this->branch = (string) $point->branch?->uuid;
            $this->names = $point->name->all();
            $this->code = $point->display_code;
            $this->prefix = (string) $point->ticket_prefix;
            $this->department = (string) $point->department?->uuid;
            $this->resource = (string) $point->resource?->uuid;
            $this->active = $point->is_active;
            $this->sort = $point->sort_order;
        });
    }

    public function closePanel(): void
    {
        $this->resetForm();
    }

    public function save(SaveServicePoint $save, QueueSetupQuery $setup, TenantLocales $locales): void
    {
        $this->validate([
            'names.'.$locales->default() => ['required', 'string', 'max:120'],
            'names.*' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:8'],
            'prefix' => ['nullable', 'string', 'max:4'],
            'sort' => ['integer', 'min:0', 'max:9999'],
        ], [], [
            'names.'.$locales->default() => __('manager_queue.setup.point_name'),
            'code' => __('manager_queue.setup.code'),
            'prefix' => __('manager_queue.setup.prefix'),
        ]);

        $this->attempt(function () use ($save, $setup): void {
            $point = $this->editing === 'new' ? null : $setup->point($this->viewer(), $this->editing);

            $save([
                'branch' => $this->branch,
                'name' => array_filter($this->names, static fn (string $v): bool => trim($v) !== ''),
                'display_code' => $this->code,
                'ticket_prefix' => $this->prefix === '' ? null : $this->prefix,
                'department' => $this->department === '' ? null : $this->department,
                'resource' => $this->resource === '' ? null : $this->resource,
                'is_active' => $this->active,
                'sort_order' => $this->sort,
            ], $this->viewer(), $point);

            $this->resetForm();
            $this->succeeded(__('manager_queue.setup.point_saved'));
        });
    }

    public function archive(string $uuid, SaveServicePoint $save, QueueSetupQuery $setup): void
    {
        $this->attempt(function () use ($uuid, $save, $setup): void {
            $point = $save->archive($setup->point($this->viewer(), $uuid), $this->viewer());

            $this->succeeded(__('manager_queue.setup.point_archived', ['code' => $point->display_code]));
        });
    }

    public function render(QueueSetupQuery $setup, VisitOptions $options, TenantLocales $locales, Entitlements $entitlements): View
    {
        $viewer = $this->viewer();
        $branch = $options->branch($viewer, $this->branch);

        return view('livewire.center.queue.service-points', [
            'points' => $setup->servicePoints($viewer, $this->archived),
            'branches' => $options->branches($viewer),
            'departments' => $options->departments(),
            'resources' => $branch === null ? [] : $setup->resources($branch),
            'locales' => $locales->enabled(),
            'primary' => $locales->default(),
            'entitled' => $entitlements->enabled('queue_management'),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editing', 'branch', 'names', 'code', 'prefix', 'department', 'resource', 'active', 'sort']);
        $this->resetErrorBag();
    }
}
