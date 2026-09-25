<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog\Concerns;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Resources\Application\Actions\SetServiceResourceRequirements;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "rooms and equipment" part of the service drawer.
 *
 * Requirements belong to the Resources module, which imports Catalog — never
 * the reverse — so the drawer writes them through Resources' own Action, in
 * the same tenant transaction as the service. They are shown with
 * `resource.view` and written only with `resource.manage`, and only when they
 * actually changed: re-sending an unchanged list would take the branch locks
 * for nothing.
 *
 * @phpstan-require-extends Component
 */
trait EditsResourceRequirements
{
    /** @var list<array{type: string, quantity: int|string}> */
    public array $requirements = [];

    #[Locked]
    public string $requirementsLoaded = '[]';

    public function addRequirement(): void
    {
        $this->requirements[] = ['type' => '', 'quantity' => 1];
    }

    public function removeRequirement(int $index): void
    {
        unset($this->requirements[$index]);
        $this->requirements = array_values($this->requirements);
    }

    protected function loadRequirements(Service $service, User $viewer): void
    {
        if ($viewer->hasPermission(Permission::ResourceView)) {
            $this->requirements = ServiceResourceRequirement::query()->with('type')
                ->where('service_id', $service->id)->orderBy('id')->get()
                ->map(static fn (ServiceResourceRequirement $r): array => ['type' => (string) $r->type?->uuid, 'quantity' => $r->quantity])
                ->values()->all();
        }

        $this->requirementsLoaded = (string) json_encode($this->requirementRows());
    }

    /**
     * Call inside the service's tenant transaction.
     */
    protected function saveRequirements(Service $service, User $user): void
    {
        if ($user->hasPermission(Permission::ResourceManage) && $this->requirementsChanged()) {
            app(SetServiceResourceRequirements::class)($service, $this->requirementRows(), $user);
        }
    }

    /**
     * @return list<array{type: string, quantity: int}>
     */
    private function requirementRows(): array
    {
        $rows = [];

        foreach ($this->requirements as $row) {
            if ($row['type'] !== '') {
                $rows[] = ['type' => (string) $row['type'], 'quantity' => (int) $row['quantity']];
            }
        }

        return $rows;
    }

    private function requirementsChanged(): bool
    {
        return (string) json_encode($this->requirementRows()) !== $this->requirementsLoaded;
    }
}
