<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Identity\Models\User;
use App\Modules\CenterSite\Application\SiteAccess;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use App\Modules\CenterSite\Domain\SiteEditor;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Manager → Appearance → Site: the center's landing page builder.
 *
 * Orchestration only. The structural operations (add, duplicate, remove,
 * reorder sections and items) are SiteEditor's pure functions; validation is
 * the SiteContent allow-list; saving, publishing and restoring are
 * SitePublisher's, which authorises and audits. Uploads live in the
 * SiteMediaSlot child and history in SiteHistory; panels are Blade partials fed
 * by SiteEditorView.
 *
 * Works on the DRAFT: the public page changes only on Publish.
 */
#[Layout('components.layouts.app')]
final class Site extends Component
{
    /** @var array<string, mixed> the draft being edited */
    public array $content = [];

    /** `header`, `navigation`, `hero`, `section:<id>`, `footer`, `seo`, `history`. */
    public string $panel = 'hero';

    public bool $choosingSection = false;

    public ?string $removingSection = null;

    public bool $confirmingPublish = false;

    /** Something changed since the last save. */
    public bool $unsaved = false;

    /** @var array<string, array{number: string, country: string}> footer phone + WhatsApp (SitePhones) */
    public array $phones = [];

    public function mount(SitePublisher $publisher): void
    {
        abort_unless(SiteAccess::canView($this->actor()), 403);
        $this->load($publisher->editableContent());
    }

    public function setPanel(string $panel): void
    {
        if (in_array($panel, ['header', 'navigation', 'hero', 'footer', 'seo', 'history'], true)
            || (str_starts_with($panel, 'section:') && isset($this->content['sections'][mb_substr($panel, 8)]))) {
            $this->panel = $panel;
        }
    }

    public function updated(string $name, mixed $value): void
    {
        if (str_starts_with($name, 'phones.')) {
            $this->unsaved = true;
        }
        if (! str_starts_with($name, 'content.')) {
            return;
        }
        $this->unsaved = true;

        // A new kind of destination starts from a valid target of that kind.
        if (str_ends_with($name, '.link_type')) {
            data_set($this->content, mb_substr($name, 8, -10).'.target', match ($value) {
                'section' => SiteEditor::anchors($this->content)[0] ?? '',
                'page' => 'booking',
                default => '',
            });
        }
    }

    // ── Sections ────────────────────────────────────────────────────────────

    public function addSection(string $type): void
    {
        $this->guard();
        [$this->content, $id] = SiteEditor::addSection($this->content, $type, $this->currentSection());
        $this->choosingSection = false;
        if ($id !== null) {
            $this->panel = 'section:'.$id;
            $this->unsaved = true;
        }
    }

    public function duplicateSection(string $id): void
    {
        $this->guard();
        [$this->content, $newId] = SiteEditor::duplicateSection($this->content, $id);
        if ($newId !== null) {
            $this->panel = 'section:'.$newId;
            $this->unsaved = true;
        }
    }

    public function askRemoveSection(string $id): void
    {
        $this->removingSection = isset($this->content['sections'][$id]) ? $id : null;
    }

    public function removeSection(): void
    {
        $this->guard();
        $id = (string) $this->removingSection;
        $this->content = SiteEditor::removeSection($this->content, $id);
        $this->removingSection = null;
        if ($this->panel === 'section:'.$id) {
            $this->panel = 'hero';
        }
        $this->unsaved = true;
    }

    public function toggleSection(string $id): void
    {
        $this->guard();
        $this->content = SiteEditor::toggleSection($this->content, $id);
        $this->unsaved = true;
    }

    public function moveSection(string $id, int $direction): void
    {
        $this->guard();
        $this->content = SiteEditor::moveSection($this->content, $id, $direction);
        $this->unsaved = true;
    }

    /** Drag and drop / keyboard: one section to one position. */
    public function sortSections(string $id, int $index): void
    {
        $this->guard();
        $this->content = SiteEditor::placeSection($this->content, $id, $index);
        $this->unsaved = true;
    }

    // ── Repeatable items (menu, footer links, social, section items) ─────────

    public function addItem(string $list): void
    {
        $this->guard();
        [$this->content] = SiteEditor::addItem($this->content, $list);
        $this->unsaved = true;
    }

    public function duplicateItem(string $list, string $id): void
    {
        $this->guard();
        $this->content = SiteEditor::duplicateItem($this->content, $list, $id);
        $this->unsaved = true;
    }

    public function removeItem(string $list, string $id): void
    {
        $this->guard();
        $this->content = SiteEditor::removeItem($this->content, $list, $id);
        $this->unsaved = true;
    }

    public function moveItem(string $list, string $id, int $direction): void
    {
        $this->guard();
        $this->content = SiteEditor::moveItem($this->content, $list, $id, $direction);
        $this->unsaved = true;
    }

    /** Drag and drop: `$key` is `<list>|<item id>`. */
    public function sortItems(string $key, int $index): void
    {
        $this->guard();
        [$list, $id] = array_pad(explode('|', $key, 2), 2, '');
        $this->content = SiteEditor::placeItem($this->content, $list, $id, $index);
        $this->unsaved = true;
    }

    // ── Media ───────────────────────────────────────────────────────────────

    /** A SiteMediaSlot uploaded a file into the library; point the slot at it. */
    #[On('site-media-selected')]
    public function mediaSelected(string $target, string $uuid): void
    {
        $this->guard();
        if (SiteEditorView::isMediaTarget($this->content, $target)) {
            data_set($this->content, $target, $uuid);
            $this->unsaved = true;
        }
    }

    /** Gallery bulk upload: one new item per uploaded image. */
    #[On('site-media-added')]
    public function galleryAdded(string $section, string $uuid): void
    {
        $this->guard();
        [$content, $itemId] = SiteEditor::addItem($this->content, 'sections.'.$section.'.items');
        if ($itemId !== null && ($this->content['sections'][$section]['type'] ?? '') === 'gallery') {
            $items = $content['sections'][$section]['items'];
            $items[count($items) - 1]['image'] = $uuid;
            $content['sections'][$section]['items'] = $items;
            $this->content = $content;
            $this->unsaved = true;
        }
    }

    /** Removing only unreferences: published and archived versions may still use the file. */
    public function clearMedia(string $target): void
    {
        $this->guard();
        if (SiteEditorView::isMediaTarget($this->content, $target)) {
            data_set($this->content, $target, '');
            $this->unsaved = true;
        }
    }

    // ── Save, preview, publish ──────────────────────────────────────────────

    public function saveDraft(SitePublisher $publisher): bool
    {
        return $this->attempt(function () use ($publisher): void {
            $this->load($publisher->saveDraft(SitePhones::merge($this->content, $this->phones), $this->actor())->content);
            session()->flash('notice', __('manager_site.saved'));
        });
    }

    /** Saves, then opens the preview of what was saved. */
    public function preview(SitePublisher $publisher): void
    {
        if ($this->saveDraft($publisher)) {
            $this->dispatch('site-preview-open');
        }
    }

    public function publish(SitePublisher $publisher): void
    {
        $this->confirmingPublish = false;
        $this->attempt(function () use ($publisher): void {
            $version = $publisher->publish($this->actor(), SitePhones::merge($this->content, $this->phones));
            $this->load($version->content);
            session()->flash('notice', __('manager_site.published', ['version' => $version->version]));
        });
    }

    #[On('site-restored')]
    public function reload(SitePublisher $publisher): void
    {
        $this->load($publisher->editableContent());
        $this->unsaved = false;
        $this->panel = 'hero';
    }

    public function render(SiteEditorView $view): mixed
    {
        abort_unless(SiteAccess::canView($this->actor()), 403);

        return view('livewire.center.appearance.site', $view->data($this->content, $this->panel, $this->actor()))
            ->title(__('manager_site.title'));
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Runs a save/publish, mapping a refusal to the field that caused it and
     * opening the panel it lives in.
     */
    private function attempt(callable $work): bool
    {
        $this->resetErrorBag();
        try {
            $work();
        } catch (InvalidSiteContent $e) {
            $this->addError('content', $e->getMessage());
            if ($e->path !== '') {
                $this->addError('content.'.$e->path, $e->getMessage());
                if (($phoneField = SitePhones::errorKey($e->path)) !== null) {
                    $this->addError($phoneField, $e->getMessage());
                }
                $this->panel = SiteEditorView::panelFor($this->content, $e->path) ?? $this->panel;
            }

            return false;
        } catch (AuthorizationException $e) {
            $this->addError('content', $e->getMessage());

            return false;
        }
        $this->unsaved = false;
        $this->dispatch('site-saved');

        return true;
    }

    /**
     * @param  array<string, mixed>  $content  stored content
     */
    private function load(array $content): void
    {
        $this->content = SiteEditorView::fresh($content);
        $this->phones = SitePhones::split($this->content);
    }

    private function currentSection(): ?string
    {
        return str_starts_with($this->panel, 'section:') ? mb_substr($this->panel, 8) : null;
    }

    /** Structural edits are writes in waiting: only managers make them. */
    private function guard(): void
    {
        abort_unless(SiteAccess::canManage($this->actor()), 403);
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
