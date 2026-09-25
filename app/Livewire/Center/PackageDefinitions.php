<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\Benefits\Concerns\ChoosesServices;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Packages\Application\Actions\ManagePackageDefinition;
use App\Modules\Packages\Application\PackagesAccess;
use App\Modules\Packages\Application\PackagesPresenter;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Service packages: prepaid sessions a center sells.
 *
 * A package is sold at the till like any other line and becomes the
 * customer's when its invoice is settled; a session is used only by a service
 * actually PERFORMED at checkout (ADR-065). This page defines, edits, archives
 * and restores what is sold, and shows who holds one with how many sessions
 * are left. Editing changes what is sold next — every package already sold is
 * a snapshot (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§13, 15, 27).
 */
#[Layout('components.layouts.app')]
final class PackageDefinitions extends Component
{
    use ChoosesServices;
    use RequiresFeature;

    /** @var array<string, string> */
    public array $name = [];

    public string $price = '';

    public string $validityDays = '90';

    public string $sortOrder = '0';

    /** @var list<array{service: string, variation?: string, quantity: string}> */
    public array $items = [['service' => '', 'variation' => '', 'quantity' => '5']];

    /** The definition being edited, by uuid; null while adding. */
    public ?string $editing = null;

    #[Url(except: 'active')]
    public string $view = 'active';

    public string $error = '';

    public string $saved = '';

    public bool $showForm = false;

    public function openForm(): void
    {
        $this->reset(['error', 'saved', 'name', 'price', 'validityDays', 'sortOrder', 'items', 'editing']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $uuid, PackagesQuery $query, PackagesPresenter $presenter): void
    {
        $this->reset(['error', 'saved']);
        $this->resetValidation();

        try {
            $definition = $presenter->definition($query->definition($uuid, $this->user()));
        } catch (AuthorizationException $refused) {
            $this->error = $refused->getMessage();

            return;
        } catch (NotFoundHttpException) {
            $this->error = __('manager_benefits.errors.not_found');

            return;
        }

        /** @var array<string, string> $names */
        $names = $definition['names'];
        /** @var list<array<string, mixed>> $items */
        $items = $definition['items'];

        $this->editing = $uuid;
        $this->name = $names;
        $this->price = Money::fromMinor((int) $definition['price']['amount'], Currency::default())->toMajorString();
        $this->validityDays = (string) $definition['validity_days'];
        $this->sortOrder = (string) $definition['sort_order'];
        $this->items = array_map(static fn (array $item): array => [
            'service' => (string) ($item['service'] ?? ''),
            'variation' => (string) ($item['variation'] ?? ''),
            'quantity' => (string) $item['quantity'],
        ], $items) ?: [['service' => '', 'variation' => '', 'quantity' => '5']];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editing = null;
        $this->resetValidation();
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'archived' ? 'archived' : 'active';
    }

    /** A new service on a row clears the variation chosen for the old one. */
    public function updatedItems(mixed $value, ?string $key = null): void
    {
        if ($key !== null && str_ends_with($key, '.service')) {
            $index = (int) explode('.', $key)[0];

            if (isset($this->items[$index])) {
                $this->items[$index]['variation'] = '';
            }
        }
    }

    public function addItemRow(): void
    {
        if (count($this->items) < ManagePackageDefinition::MAX_ITEMS) {
            $this->items[] = ['service' => '', 'variation' => '', 'quantity' => '1'];
        }
    }

    public function removeItemRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(ManagePackageDefinition $manage, PackagesQuery $query): void
    {
        $this->guard(function () use ($manage, $query): void {
            $existing = $this->editing === null ? null : $query->definition($this->editing, $this->user());

            $items = array_map(static fn (array $row): array => [
                'service' => (string) $row['service'],
                'variation' => ($row['variation'] ?? '') === '' ? null : (string) $row['variation'],
                'quantity' => (int) $row['quantity'],
            ], $this->items);

            $manage->save($this->user(), $this->name, $this->minor($this->price), (int) $this->validityDays, $items, max(0, (int) $this->sortOrder), $existing);

            $this->reset(['name', 'price', 'validityDays', 'sortOrder', 'items', 'editing']);
            $this->saved = $existing === null ? __('manager_benefits.packages.saved') : __('manager_benefits.packages.updated');
        });

        if ($this->error === '') {
            $this->showForm = false;
        }
    }

    public function archive(string $uuid, ManagePackageDefinition $manage, PackagesQuery $query): void
    {
        $this->guard(function () use ($uuid, $manage, $query): void {
            $manage->archive($this->user(), $query->definition($uuid, $this->user()));
            $this->saved = __('manager_benefits.packages.archived');
        });
    }

    public function restore(string $uuid, ManagePackageDefinition $manage, PackagesQuery $query): void
    {
        $this->guard(function () use ($uuid, $manage, $query): void {
            $manage->restore($this->user(), $query->definition($uuid, $this->user()));
            $this->saved = __('manager_benefits.packages.restored');
        });
    }

    public function render(PackagesQuery $query, PackagesPresenter $presenter, PackagesAccess $access, TenantLocales $locales): View
    {
        $user = $this->user();

        if (! $user->hasPermission(Permission::PackageView)) {
            abort(403);
        }

        $offer = $this->lockedFeature('packages');

        if ($offer !== null && ! $query->hasHistory()) {
            return view('livewire.center.feature-locked', ['offer' => $offer])->title(__('ui.manager_nav.items.packages'));
        }

        $all = [];
        $counts = [];

        try {
            $all = array_map(fn (PackageDefinition $definition): array => $presenter->definition($definition), $query->definitions($user, true));
            $counts = $query->liveCountsByDefinition($user);
        } catch (AuthorizationException $failure) {
            $this->error = $failure->getMessage();
        }

        $archived = array_values(array_filter($all, static fn (array $definition): bool => $definition['archived'] === true));
        $active = array_values(array_filter($all, static fn (array $definition): bool => $definition['archived'] !== true));
        $canManage = $offer === null && $access->enabled() && $user->hasPermission(Permission::PackageManage);
        $services = $canManage && $this->showForm ? $this->serviceChoices(true) : [];
        $variations = [];

        foreach ($services as $service) {
            $variations[$service['uuid']] = $service['variations'];
        }

        return view('livewire.center.package-definitions', [
            'offer' => $offer,
            'definitions' => array_map(fn (array $definition): array => $definition + ['holders' => $counts[$definition['uuid']] ?? 0], $this->view === 'archived' ? $archived : $active),
            'activeCount' => count($active),
            'archivedCount' => count($archived),
            'services' => $services,
            'variations' => $variations,
            'currency' => Currency::default()->value,
            'maxItems' => ManagePackageDefinition::MAX_ITEMS,
            'canManage' => $canManage,
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
        ])->title(__('ui.manager_nav.items.packages'));
    }

    /**
     * @throws PackagesFailed
     */
    private function minor(string $typed): int
    {
        try {
            return Money::fromMajorString(trim($typed) === '' ? '0' : $typed, Currency::default())->minor;
        } catch (InvalidArgumentException) {
            throw PackagesFailed::policy(__('manager_benefits.errors.amount_format'));
        }
    }

    private function guard(callable $work): void
    {
        $this->reset(['error', 'saved']);

        try {
            $work();
        } catch (PackagesFailed|AuthorizationException|EntitlementRequired $failure) {
            $this->error = $failure->getMessage();
        } catch (NotFoundHttpException) {
            $this->error = __('manager_benefits.errors.not_found');
        }
    }

    private function user(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
