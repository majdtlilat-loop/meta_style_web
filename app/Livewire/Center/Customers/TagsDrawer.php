<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Customers\Application\Actions\ManageCustomerTag;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Customer tags, managed beside the CRM list: add, rename, archive, restore.
 * Names are content — one text per enabled content language. Every change goes
 * through `ManageCustomerTag` (`customer.tag.manage`); the list is told to
 * redraw its filters afterwards.
 */
final class TagsDrawer extends Component
{
    public bool $open = false;

    /** @var array<string, string> */
    public array $tagName = [];

    /** The tag being renamed, by uuid; null while adding. */
    public ?string $editingTag = null;

    public string $notice = '';

    public string $noticeTone = 'success';

    #[On('open-customer-tags')]
    public function show(): void
    {
        $this->reset(['tagName', 'editingTag', 'notice']);
        $this->resetValidation();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function rename(string $uuid): void
    {
        $tag = $this->find($uuid);

        $this->resetValidation();
        $this->editingTag = $uuid;
        $this->tagName = $tag->name->all();
    }

    public function cancelRename(): void
    {
        $this->reset(['tagName', 'editingTag']);
        $this->resetValidation();
    }

    public function saveTag(ManageCustomerTag $manage): void
    {
        $this->act(function () use ($manage): void {
            $manage->save($this->user(), $this->tagName, $this->editingTag === null ? null : $this->find($this->editingTag));
            $this->flash($this->editingTag === null ? __('manager_customers.tags.added') : __('manager_customers.tags.saved'));
            $this->reset(['tagName', 'editingTag']);
        });
    }

    public function archiveTag(string $uuid, ManageCustomerTag $manage): void
    {
        $this->act(function () use ($uuid, $manage): void {
            $manage->archive($this->user(), $this->find($uuid));
            $this->flash(__('manager_customers.tags.archived'));
        });
    }

    public function restoreTag(string $uuid, ManageCustomerTag $manage): void
    {
        $this->act(function () use ($uuid, $manage): void {
            $manage->restore($this->user(), $this->find($uuid));
            $this->flash(__('manager_customers.tags.restored'));
        });
    }

    public function render(TenantLocales $locales): View
    {
        $user = $this->user();
        $canManage = $user->hasPermission(Permission::CustomerTagManage);

        $tags = $this->open && $canManage
            ? CustomerTag::query()->withCount('customers')->orderByRaw('archived_at IS NULL DESC')->orderBy('sort_order')->orderBy('id')->limit(100)->get()
                ->map(fn (CustomerTag $tag): array => [
                    'uuid' => $tag->uuid,
                    'name' => (string) $tag->name->get(),
                    'customers' => (int) $tag->getAttribute('customers_count'),
                    'archived' => $tag->archived_at !== null,
                ])->values()->all()
            : [];

        return view('livewire.center.customers.tags-drawer', [
            'canManage' => $canManage,
            'tags' => $tags,
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
        ]);
    }

    private function act(callable $work): void
    {
        try {
            $work();
        } catch (ValidationException $invalid) {
            $this->addError('tagName.'.app(TenantLocales::class)->default(), (string) collect($invalid->errors())->flatten()->first());

            return;
        } catch (AuthorizationException $refused) {
            $this->flash($refused->getMessage(), 'danger');

            return;
        }

        $this->dispatch('customer-tags-changed');
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function find(string $uuid): CustomerTag
    {
        /** @var CustomerTag $tag */
        $tag = CustomerTag::query()->where('uuid', $uuid)->firstOrFail();

        return $tag;
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
