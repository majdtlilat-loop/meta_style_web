<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\RegisteredCenterAddress;
use App\Livewire\Center\Appearance\Support\MenuOptions;
use App\Modules\Menu\Application\MenuPublisher;
use App\Modules\Menu\Application\PublicBrand;
use App\Modules\Menu\Domain\MenuPresentation;
use App\Modules\Menu\Domain\MenuPresentationRejected;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * The menu appearance editor (Manager → Appearance → Menu).
 *
 * Configuration, not a page builder. Every control here is a choice from
 * `config/menu.php`, and there is no field that accepts markup — a
 * center-authored script on a guest-accessible page is stored XSS against that
 * center's own customers (docs/13-ROADMAP.md Phase 4 §14).
 *
 * Editing writes to a draft, so the live page does not change under a customer
 * who is reading it; the preview renders the draft through the real public
 * template. `menu.view` to open the page, `menu.manage` to change anything —
 * both checked on the server, the second inside {@see MenuPublisher}.
 */
#[Layout('components.layouts.app')]
final class MenuDesigner extends Component
{
    public string $templateKey = 'minimal';

    /** @var array<string, string> */
    public array $theme = [];

    /** @var list<array{key: string, visible: bool, config: array<string, string|bool|int>}> */
    public array $sections = [];

    public string $notice = '';

    public string $noticeTone = 'success';

    public bool $confirmingPublish = false;

    public function mount(MenuPublisher $publisher): void
    {
        $this->actor(Permission::MenuView);

        // A read: opening the editor never creates a draft row.
        $this->applyPresentation($publisher->previewPresentation());
    }

    /**
     * Picking a template applies its whole look — colours included — to the
     * draft being edited. Nothing is live until a publish.
     */
    public function updatedTemplateKey(string $value): void
    {
        if (array_key_exists($value, (array) config('menu.templates', []))) {
            $this->theme = array_map(static fn (mixed $v): string => (string) $v, MenuPresentation::preset($value));
        }
    }

    /**
     * Drag-and-drop and the Move up / Move down buttons both land here. The
     * order is the page's section order; nothing else moves.
     */
    public function moveSection(string $key, int $position): void
    {
        $keys = array_column($this->sections, 'key');
        $from = array_search($key, $keys, true);

        if ($from === false) {
            return;
        }

        $position = max(0, min(count($this->sections) - 1, $position));
        $moved = array_splice($this->sections, $from, 1);
        array_splice($this->sections, $position, 0, $moved);
    }

    public function saveDraft(MenuPublisher $publisher): bool
    {
        $this->resetErrorBag();

        try {
            $publisher->saveDraft($this->input(), $this->actor(Permission::MenuView));
        } catch (AuthorizationException) {
            return $this->fail(__('manager_appearance.errors.forbidden'));
        } catch (MenuPresentationRejected $e) {
            // Shown, never silently dropped: the owner must see that what
            // they configured was not accepted.
            $this->addError(in_array($e->reason, ['unknown_section', 'duplicate_section', 'no_sections', 'unknown_setting', 'out_of_range', 'missing_key'], true) ? 'sections' : 'theme', MenuOptions::message($e));

            return false;
        }

        $this->say(__('manager_appearance.menu.draft_saved'));

        return true;
    }

    public function confirmPublish(): void
    {
        $this->confirmingPublish = true;
    }

    public function publish(MenuPublisher $publisher): void
    {
        $this->confirmingPublish = false;

        if (! $this->saveDraft($publisher)) {
            return;
        }

        try {
            $version = $publisher->publish($this->actor(Permission::MenuView));
        } catch (AuthorizationException) {
            $this->fail(__('manager_appearance.errors.forbidden'));

            return;
        } catch (ValidationException) {
            $this->fail(__('manager_appearance.menu.nothing_to_publish'));

            return;
        }

        $this->say(__('manager_appearance.menu.published', ['version' => $version->version]));
    }

    /**
     * Saves the draft so the preview shows exactly what is on screen, then
     * asks the page to open it. A draft is never live, so this is safe.
     */
    public function preview(MenuPublisher $publisher): void
    {
        if ($this->actor(Permission::MenuView)->hasPermission(Permission::MenuManage) && ! $this->saveDraft($publisher)) {
            return;
        }

        $this->notice = '';
        $this->dispatch('menu-preview');
    }

    public function rollback(string $uuid, MenuPublisher $publisher): void
    {
        /** @var MenuVersion|null $target */
        $target = MenuVersion::query()->where('uuid', $uuid)->first();

        if (! $target instanceof MenuVersion) {
            $this->fail(__('manager_appearance.menu.version_missing'));

            return;
        }

        try {
            $version = $publisher->rollbackTo($target, $this->actor(Permission::MenuView));

            // The editor continues from what is now live, so the draft follows
            // the restore instead of silently keeping older unpublished edits.
            $publisher->saveDraft($version->presentation()->toArray(), $this->actor(Permission::MenuView));
        } catch (AuthorizationException) {
            $this->fail(__('manager_appearance.errors.forbidden'));

            return;
        } catch (ValidationException|MenuPresentationRejected) {
            $this->fail(__('manager_appearance.menu.cannot_restore'));

            return;
        }

        $this->applyPresentation($version->presentation());
        $this->say(__('manager_appearance.menu.restored', ['version' => $target->version, 'new' => $version->version]));
    }

    public function render(MenuPublisher $publisher, TenantContext $tenants, RegisteredCenterAddress $addresses, TenantLocales $locales, LanguageRegistry $languages, PublicBrand $brand): View
    {
        $user = $this->actor(Permission::MenuView);
        $published = $publisher->published();
        /** @var MenuVersion|null $draft */
        $draft = MenuVersion::query()->draft()->first();
        $tenant = TenantModel::query()->with('domains')->find($tenants->require()->id);
        $address = $tenant instanceof TenantModel ? $addresses->resolve($tenant) : ['available' => false, 'urls' => null];
        $timezone = $tenant?->timezone ?: 'UTC';

        return view('livewire.center.menu-designer', [
            'templates' => MenuOptions::templates(),
            'groups' => MenuOptions::groups(),
            'sectionRows' => MenuOptions::sections($this->sections),
            'published' => $published === null ? null : [
                'version' => $published->version,
                'at' => $published->published_at?->setTimezone($timezone)->translatedFormat('j M Y, H:i'),
            ],
            'dirty' => $this->isDirty($draft, $published),
            'history' => MenuVersion::query()->archived()->with('publishedBy')->limit(10)->get()
                ->map(static fn (MenuVersion $v): array => [
                    'uuid' => $v->uuid,
                    'version' => $v->version,
                    'template' => __('manager_appearance.menu.templates.'.$v->template_key.'.label'),
                    'at' => $v->published_at?->setTimezone($timezone)->translatedFormat('j M Y, H:i') ?? '—',
                    'by' => $v->publishedBy === null ? __('manager_appearance.menu.by_system') : $v->publishedBy->name,
                ])->all(),
            'canManage' => $user->hasPermission(Permission::MenuManage),
            'publicUrl' => $address['available'] ? ($address['urls']['list'] ?? null) : null,
            'previewLocales' => array_map(static fn (string $code): array => ['code' => $code, 'label' => $languages->shortLabel($code)], $locales->enabled()),
            'primaryLocale' => $locales->default(),
            'brandAvailable' => $brand->resolve() !== null,
        ])->title(__('manager_appearance.menu.title'));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'template_key' => $this->templateKey,
            'theme' => $this->theme,
            'sections' => $this->sections,
        ];
    }

    private function applyPresentation(MenuPresentation $presentation): void
    {
        $this->templateKey = $presentation->templateKey;
        $this->theme = array_map(static fn (mixed $v): string => (string) $v, $presentation->theme);
        $this->sections = $presentation->sections;
    }

    private function isDirty(?MenuVersion $draft, ?MenuVersion $published): bool
    {
        if (! $draft instanceof MenuVersion) {
            return false;
        }

        if (! $published instanceof MenuVersion) {
            return true;
        }

        try {
            return ! $draft->presentation()->sameAs($published->presentation());
        } catch (Throwable) {
            return true;
        }
    }

    private function say(string $message): void
    {
        $this->notice = $message;
        $this->noticeTone = 'success';
    }

    private function fail(string $message): bool
    {
        $this->notice = $message;
        $this->noticeTone = 'danger';

        return false;
    }

    private function actor(Permission $permission): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission($permission), 403);

        return $user;
    }
}
