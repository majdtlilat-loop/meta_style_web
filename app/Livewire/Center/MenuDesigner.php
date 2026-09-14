<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Menu\Application\MenuPublisher;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The menu appearance editor.
 *
 * Configuration, not a page builder. Every control here is a choice from
 * `config/menu.php`, and there is no field that accepts markup — a
 * center-authored script on a guest-accessible page is stored XSS against that
 * center's own customers (docs/13-ROADMAP.md Phase 4 §14).
 *
 * Editing writes to a draft, so the live page does not change under a customer
 * who is reading it.
 */
#[Layout('components.layouts.app')]
final class MenuDesigner extends Component
{
    public string $templateKey = 'minimal';

    /** @var array<string, string> */
    public array $theme = [];

    /** @var array<int, array{key: string, visible: bool, config: array<string, string|bool|int>}> */
    public array $sections = [];

    public string $notice = '';

    public function mount(MenuPublisher $publisher): void
    {
        $draft = $publisher->draft();

        $this->templateKey = $draft->template_key;
        $this->theme = array_map(static fn (mixed $v): string => (string) $v, $draft->theme);
        $this->sections = $draft->sections;
    }

    public function moveSection(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->sections[$index], $this->sections[$target])) {
            return;
        }

        [$this->sections[$index], $this->sections[$target]] = [$this->sections[$target], $this->sections[$index]];
    }

    public function saveDraft(MenuPublisher $publisher): void
    {
        try {
            $publisher->saveDraft([
                'template_key' => $this->templateKey,
                'theme' => $this->theme,
                'sections' => array_values($this->sections),
            ], $this->actor());
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();

            return;
        } catch (InvalidArgumentException $e) {
            // Rejected values are shown, not silently dropped: the owner must
            // see that what they configured was not accepted.
            $this->addError('theme', $e->getMessage());

            return;
        }

        $this->notice = __('Draft saved. Customers still see the published menu.');
    }

    public function publish(MenuPublisher $publisher): void
    {
        $this->saveDraft($publisher);

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $version = $publisher->publish($this->actor());
        } catch (AuthorizationException|ValidationException $e) {
            $this->notice = $e->getMessage();

            return;
        }

        $this->notice = __('Published as version :v.', ['v' => $version->version]);
    }

    public function rollback(string $uuid, MenuPublisher $publisher): void
    {
        try {
            $version = $publisher->rollbackTo(
                MenuVersion::query()->where('uuid', $uuid)->firstOrFail(),
                $this->actor(),
            );
        } catch (AuthorizationException|ValidationException $e) {
            $this->notice = $e->getMessage();

            return;
        }

        $this->notice = __('Restored as version :v.', ['v' => $version->version]);
    }

    public function render(MenuPublisher $publisher, TenantContext $tenants): mixed
    {
        $published = $publisher->published();

        return view('livewire.center.menu-designer', [
            'templates' => array_keys((array) config('menu.templates', [])),
            'themeOptions' => (array) config('menu.theme.options', []),
            'colorKeys' => (array) config('menu.theme.colors', []),
            'sectionCatalog' => (array) config('menu.sections', []),
            'published' => $published,
            'history' => MenuVersion::query()->archived()->limit(10)->get(),
            'canManage' => $this->actor()->hasPermission(Permission::MenuManage),
            // The live page, so the owner can open exactly what a customer sees.
            'publicUrl' => url('/m/'.$tenants->require()->publicKey),
        ]);
    }

    private function actor(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
