<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Cms;

use App\Kernel\Audit\Actor;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\LandingCms\Application\Actions\SaveLandingPage;
use App\Modules\LandingCms\Domain\LandingContent;
use App\Modules\LandingCms\Domain\Models\LandingPage;
use DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The corporate landing page builder.
 *
 * Works on the DRAFT only; the public page changes when a Super Admin
 * publishes. Every save goes through SaveLandingPage, which normalizes the
 * whole document through the allow-listed schema, stores a revision and audits
 * it. Nothing here accepts HTML, CSS, script or an arbitrary asset path.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithFileUploads;

    /** Approved media slots: `<path>` => image|video. Section slots are matched by pattern. */
    private const FIXED_MEDIA = [
        'hero.image' => 'image', 'hero.video' => 'video', 'hero.poster_image' => 'image',
        'hero.background_image' => 'image', 'hero.background_video' => 'video', 'seo.og_image' => 'image',
    ];

    /** @var array<string, mixed> */
    public array $content = [];

    /** `header`, `menu`, `hero`, `section:<id>`, `footer`, `seo`, `history`. */
    public string $activePanel = 'hero';

    /** @var mixed */
    public $mediaUpload = null;

    public string $mediaTarget = 'hero.background_image';

    public bool $choosingSection = false;

    public bool $confirmingPublish = false;

    public ?string $removingSection = null;

    public function mount(): void
    {
        $this->content = LandingContent::hydrate($this->page()->draft_content);
    }

    public function setPanel(string $panel): void
    {
        $valid = in_array($panel, ['header', 'menu', 'hero', 'footer', 'seo', 'history'], true)
            || (str_starts_with($panel, 'section:') && isset($this->content['sections'][substr($panel, 8)]));
        if ($valid) {
            $this->activePanel = $panel;
            $this->resetValidation();
        }
    }

    // ── Menu ──────────────────────────────────────────────────────────────

    public function addNavigation(): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (count($this->content['navigation']) >= 12) {
            return;
        }
        $this->content['navigation'][] = [
            'label' => $this->localized(), 'link_type' => 'section', 'target' => (string) ($this->anchors()[0] ?? 'platform'),
            'style' => 'link', 'new_tab' => false, 'enabled' => true,
        ];
    }

    public function removeNavigation(int $index): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $this->removeAt($this->content['navigation'], $index);
    }

    public function moveNavigation(int $index, int $direction): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $this->move($this->content['navigation'], $index, $direction);
    }

    // ── Sections ──────────────────────────────────────────────────────────

    public function addSection(string $type): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        try {
            $section = LandingContent::blank($type);
        } catch (DomainException) {
            return;
        }
        $id = $this->uniqueId($type);
        $section['anchor'] = $this->uniqueAnchor(str_replace('_', '-', $type));
        $this->content['sections'][$id] = $section;
        $this->insertAfterCurrent($id);
        $this->choosingSection = false;
        $this->activePanel = 'section:'.$id;
    }

    public function duplicateSection(string $id): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $source = $this->content['sections'][$id] ?? null;
        if (! is_array($source)) {
            return;
        }
        $copy = $source;
        $newId = $this->uniqueId((string) ($source['type'] ?? 'section'));
        $copy['anchor'] = ($source['anchor'] ?? '') === '' ? '' : $this->uniqueAnchor((string) $source['anchor']);
        $this->content['sections'][$newId] = $copy;
        $this->activePanel = 'section:'.$id;
        $this->insertAfterCurrent($newId);
        $this->activePanel = 'section:'.$newId;
    }

    public function confirmRemoveSection(string $id): void
    {
        $this->removingSection = isset($this->content['sections'][$id]) ? $id : null;
    }

    public function removeSection(): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $id = $this->removingSection;
        if ($id === null || ! isset($this->content['sections'][$id])) {
            $this->removingSection = null;

            return;
        }
        unset($this->content['sections'][$id]);
        $this->content['section_order'] = array_values(array_filter($this->content['section_order'], fn ($value): bool => $value !== $id));
        $this->removingSection = null;
        if ($this->activePanel === 'section:'.$id) {
            $this->activePanel = 'hero';
        }
    }

    public function moveSection(string $id, int $direction): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $order = array_values($this->content['section_order']);
        $index = array_search($id, $order, true);
        if ($index === false) {
            return;
        }
        $this->move($order, (int) $index, $direction);
        $this->content['section_order'] = $order;
    }

    public function addSectionItem(string $section): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (! isset($this->content['sections'][$section]) || count($this->content['sections'][$section]['items'] ?? []) >= 20) {
            return;
        }
        $this->content['sections'][$section]['items'][] = LandingContent::blankItem();
    }

    public function removeSectionItem(string $section, int $index): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (isset($this->content['sections'][$section]['items']) && is_array($this->content['sections'][$section]['items'])) {
            $this->removeAt($this->content['sections'][$section]['items'], $index);
        }
    }

    public function moveSectionItem(string $section, int $index, int $direction): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (isset($this->content['sections'][$section]['items']) && is_array($this->content['sections'][$section]['items'])) {
            $this->move($this->content['sections'][$section]['items'], $index, $direction);
        }
    }

    // ── Footer ────────────────────────────────────────────────────────────

    public function addFooterLink(string $group): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if ($group === 'social_links') {
            $this->content['footer']['social_links'][] = ['network' => 'instagram', 'label' => $this->localized(), 'url' => 'https://', 'new_tab' => true, 'enabled' => true];

            return;
        }
        if (in_array($group, ['navigation', 'legal_links'], true)) {
            $this->content['footer'][$group][] = ['label' => $this->localized(), 'url' => '', 'new_tab' => false, 'enabled' => true];
        }
    }

    public function removeFooterLink(string $group, int $index): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (in_array($group, ['social_links', 'navigation', 'legal_links'], true) && is_array($this->content['footer'][$group] ?? null)) {
            $this->removeAt($this->content['footer'][$group], $index);
        }
    }

    public function addFooterGroup(): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (count($this->content['footer']['groups'] ?? []) < 4) {
            $this->content['footer']['groups'][] = ['title' => $this->localized(), 'links' => []];
        }
    }

    public function removeFooterGroup(int $index): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $this->removeAt($this->content['footer']['groups'], $index);
    }

    public function addGroupLink(int $group): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (isset($this->content['footer']['groups'][$group]) && count($this->content['footer']['groups'][$group]['links']) < 8) {
            $this->content['footer']['groups'][$group]['links'][] = ['label' => $this->localized(), 'url' => '', 'new_tab' => false, 'enabled' => true];
        }
    }

    public function removeGroupLink(int $group, int $index): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if (isset($this->content['footer']['groups'][$group]['links'])) {
            $this->removeAt($this->content['footer']['groups'][$group]['links'], $index);
        }
    }

    // ── Media ─────────────────────────────────────────────────────────────

    public function uploadMedia(): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $kind = $this->mediaKind($this->mediaTarget);
        if ($kind === null) {
            $this->addError('mediaTarget', __('sadmin_cms.errors.media_target'));

            return;
        }
        $rule = $kind === 'video'
            ? ['required', 'file', 'mimetypes:video/mp4,video/webm', 'max:30720']
            : ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=320,min_height=200,max_width=8000,max_height=8000'];
        $this->validate(['mediaUpload' => $rule], [], ['mediaUpload' => $kind === 'video' ? __('sadmin_cms.media_ui.video') : __('sadmin_cms.media_ui.image')]);
        $path = $this->mediaUpload->store('cms/landing', 'public');
        if (! is_string($path) || $path === '') {
            $this->addError('mediaUpload', __('sadmin_cms.errors.media_store'));

            return;
        }
        data_set($this->content, $this->mediaTarget, $path);
        $this->reset('mediaUpload');
    }

    public function removeMedia(string $target): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        if ($this->mediaKind($target) !== null) {
            // Only unreference it: older revisions and the published page may
            // still use the stored file.
            data_set($this->content, $target, '');
        }
    }

    // ── Revisions & publishing ────────────────────────────────────────────

    public function loadRevision(int $revisionId): void
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $revision = DB::connection('control')->table('landing_page_revisions')
            ->where('landing_page_id', $this->page()->id)->where('id', $revisionId)->first();
        if ($revision === null) {
            return;
        }
        $content = json_decode((string) $revision->content, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($content)) {
            $this->content = LandingContent::hydrate($content);
            session()->flash('notice', __('sadmin_cms.revision_loaded', ['version' => $revision->version]));
        }
    }

    public function save(SaveLandingPage $save, bool $publish = false): void
    {
        $user = $this->requirePlatformPermission('platform.cms.manage');
        $this->validate(['content' => ['required', 'array']]);
        $this->confirmingPublish = false;

        try {
            $page = $save($this->page(), $this->content, Actor::platform($user), $publish);
        } catch (DomainException $exception) {
            $this->addError('content', $exception->getMessage());

            return;
        }

        $this->content = LandingContent::hydrate($page->draft_content);
        session()->flash('notice', $publish ? __('sadmin_cms.published') : __('sadmin_cms.saved'));
    }

    public function render(): mixed
    {
        $this->requirePlatformPermission('platform.cms.manage');
        $page = $this->page();

        return view('livewire.sadmin.cms.index', [
            'page' => $page,
            'revisions' => DB::connection('control')->table('landing_page_revisions')
                ->where('landing_page_id', $page->id)->latest('version')->limit(15)->get(),
            'anchors' => $this->anchors(),
        ]);
    }

    private function page(): LandingPage
    {
        /** @var LandingPage $page */
        $page = LandingPage::query()->firstOrCreate(
            ['slug' => 'home'],
            ['title' => ['en' => 'Home', 'ar' => '', 'ckb' => ''], 'draft_content' => LandingContent::defaults(), 'draft_version' => 1, 'status' => 'draft'],
        );

        return $page;
    }

    private function mediaKind(string $target): ?string
    {
        if (isset(self::FIXED_MEDIA[$target])) {
            return self::FIXED_MEDIA[$target];
        }
        if (preg_match('/^sections\.([a-z][a-z0-9_]{1,40})\.(image|video|poster|background_image)$/', $target, $match) === 1 && isset($this->content['sections'][$match[1]])) {
            return $match[2] === 'video' ? 'video' : 'image';
        }
        if (preg_match('/^sections\.([a-z][a-z0-9_]{1,40})\.items\.(\d{1,2})\.image$/', $target, $match) === 1 && isset($this->content['sections'][$match[1]]['items'][(int) $match[2]])) {
            return 'image';
        }

        return null;
    }

    /**
     * Section anchors a menu item can point at.
     *
     * @return list<string>
     */
    private function anchors(): array
    {
        $anchors = [];
        foreach ($this->content['section_order'] ?? [] as $id) {
            $anchor = (string) ($this->content['sections'][$id]['anchor'] ?? '');
            if ($anchor !== '') {
                $anchors[] = $anchor;
            }
        }

        return $anchors;
    }

    private function uniqueId(string $type): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'section';
        $id = $base;
        $n = 2;
        while (isset($this->content['sections'][$id])) {
            $id = $base.'_'.$n++;
        }

        return $id;
    }

    private function uniqueAnchor(string $base): string
    {
        $base = trim(preg_replace('/[^a-z0-9-]/', '', strtolower($base)) ?: 'section', '-');
        $taken = array_map(fn ($section): string => (string) ($section['anchor'] ?? ''), $this->content['sections']);
        $anchor = $base;
        $n = 2;
        while (in_array($anchor, $taken, true)) {
            $anchor = $base.'-'.$n++;
        }

        return $anchor;
    }

    private function insertAfterCurrent(string $id): void
    {
        $order = array_values(array_filter($this->content['section_order'], fn ($value): bool => $value !== $id));
        $current = str_starts_with($this->activePanel, 'section:') ? substr($this->activePanel, 8) : null;
        $position = $current !== null ? array_search($current, $order, true) : false;
        if ($position === false) {
            $order[] = $id;
        } else {
            array_splice($order, (int) $position + 1, 0, [$id]);
        }
        $this->content['section_order'] = $order;
    }

    /** @return array<string, string> */
    private function localized(): array
    {
        return ['en' => '', 'ar' => '', 'ckb' => ''];
    }

    /** @param array<int, mixed> $items */
    private function removeAt(array &$items, int $index): void
    {
        if (array_key_exists($index, $items)) {
            array_splice($items, $index, 1);
        }
    }

    /** @param array<int, mixed> $items */
    private function move(array &$items, int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);
        if (! array_key_exists($index, $items) || ! array_key_exists($target, $items)) {
            return;
        }
        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        $items = array_values($items);
    }
}
