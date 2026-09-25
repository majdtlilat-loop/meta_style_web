<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog\Concerns;

use App\Kernel\Authorization\Permission;
use App\Modules\Catalog\Application\Actions\ArchiveService;
use App\Modules\Catalog\Application\Actions\DuplicateService;
use App\Modules\Catalog\Application\Actions\MoveService;
use App\Modules\Catalog\Application\Actions\SetServiceStatus;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Resources\Application\Actions\SetServiceResourceRequirements;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * The quick actions on one service row of the library: duplicate, switch on
 * or off, show or hide, online booking, archive, restore and "move to
 * category". Each is one Action; the page re-renders from the database.
 *
 * @phpstan-require-extends Component
 */
trait ServiceRowActions
{
    use CatalogFeedback;

    /** The service whose "move to category" dialog is open. */
    public ?string $movingService = null;

    /** 'none' (uncategorised) or a category uuid. */
    public string $moveTarget = 'none';

    public function duplicateService(string $uuid): void
    {
        $this->attempt(function () use ($uuid): void {
            $source = $this->serviceOrFail($uuid);
            $user = $this->actor();

            // One transaction around both Actions: the copy and its resource
            // requirements exist together or not at all. Catalog cannot copy
            // them itself — Resources imports Catalog, not the reverse.
            DB::connection('tenant')->transaction(function () use ($source, $user): void {
                $copy = app(DuplicateService::class)($source, $user, $this->copyName($source));

                // Only requirements on types still bookable: a retired type
                // would refuse the whole copy.
                $rows = ServiceResourceRequirement::query()->with('type')
                    ->where('service_id', $source->id)->get()
                    ->filter(static fn (ServiceResourceRequirement $r): bool => $r->type?->isBookable() === true)
                    ->map(static fn (ServiceResourceRequirement $r): array => ['type' => (string) $r->type?->uuid, 'quantity' => $r->quantity])
                    ->values()
                    ->all();

                if ($rows !== [] && $user->hasPermission(Permission::ResourceManage)) {
                    app(SetServiceResourceRequirements::class)($copy, $rows, $user);
                }
            });
        }, __('manager_catalog.notices.duplicated'));
    }

    public function setServiceActive(string $uuid, bool $on): void
    {
        $this->attempt(
            fn () => app(SetServiceStatus::class)($this->serviceOrFail($uuid), $this->actor(), isActive: $on),
            $on ? __('manager_catalog.notices.activated') : __('manager_catalog.notices.deactivated'),
        );
    }

    public function setServicePublic(string $uuid, bool $on): void
    {
        $this->attempt(
            fn () => app(SetServiceStatus::class)($this->serviceOrFail($uuid), $this->actor(), isPublic: $on),
            $on ? __('manager_catalog.notices.shown') : __('manager_catalog.notices.hidden'),
        );
    }

    public function setServiceOnline(string $uuid, bool $on): void
    {
        $this->attempt(
            fn () => app(SetServiceStatus::class)($this->serviceOrFail($uuid), $this->actor(), isOnlineBookable: $on),
            $on ? __('manager_catalog.notices.online_on') : __('manager_catalog.notices.online_off'),
        );
    }

    public function archiveService(string $uuid): void
    {
        $this->attempt(
            fn () => app(ArchiveService::class)($this->serviceOrFail($uuid), $this->actor()),
            __('manager_catalog.notices.archived'),
        );
    }

    public function restoreService(string $uuid): void
    {
        $this->attempt(
            fn () => app(ArchiveService::class)->restore($this->serviceOrFail($uuid), $this->actor()),
            __('manager_catalog.notices.restored'),
        );
    }

    public function startMove(string $uuid): void
    {
        $service = Service::query()->where('uuid', $uuid)->whereNull('archived_at')->first();

        if (! $service instanceof Service) {
            $this->flash(__('manager_catalog.errors.not_found'), 'danger');

            return;
        }

        $this->movingService = $service->uuid;
        $this->moveTarget = $service->category->uuid ?? 'none';
    }

    public function cancelMove(): void
    {
        $this->movingService = null;
    }

    public function confirmMove(): void
    {
        $uuid = $this->movingService;

        if ($uuid === null) {
            return;
        }

        $moved = $this->attempt(function () use ($uuid): void {
            $target = $this->moveTarget === 'none'
                ? null
                : ServiceCategory::query()->where('uuid', $this->moveTarget)->firstOrFail();

            app(MoveService::class)->toCategory($this->serviceOrFail($uuid), $target, null, $this->actor());
        }, __('manager_catalog.notices.moved'));

        if ($moved) {
            $this->movingService = null;
        }
    }

    protected function serviceOrFail(string $uuid): Service
    {
        return Service::query()->where('uuid', $uuid)->first() ?? throw new ModelNotFoundException;
    }

    /**
     * "Haircut (copy)" in every language the original has, each in its own
     * language — never an English suffix on an Arabic name.
     *
     * @return array<string, string>
     */
    private function copyName(Service $source): array
    {
        $names = [];

        foreach ($source->name->all() as $locale => $text) {
            $names[$locale] = __('manager_catalog.services.copy_name', ['name' => $text], $locale);
        }

        return $names;
    }
}
