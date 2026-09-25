<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance\Concerns;

use App\Http\Controllers\ManagerAppearancePreviewController;
use App\Kernel\Appearance\Appearance;
use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Appearance\AppearanceSchema;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Appearance\Support\MenuOptions;

/**
 * The shared mechanics of the booking, cart and print appearance editors: a
 * document held as `values` + per-language `texts`, validated only by the
 * Action (or, for a preview, by the same {@see Appearance} validator), and
 * rejections put on the exact field in the viewer's own language.
 *
 * Texts are held for EVERY supported language, not only the enabled ones: the
 * form shows the enabled ones, and the hidden ones travel back unchanged, so
 * switching a language off never loses what was written in it.
 */
trait EditsAppearanceDocument
{
    /** @var array<string, string|bool> */
    public array $values = [];

    /** @var array<string, array<string, string>> */
    public array $texts = [];

    public string $notice = '';

    public string $noticeTone = 'success';

    protected function loadDocument(Appearance $appearance): void
    {
        $this->values = $appearance->values;
        $this->texts = [];

        foreach (array_keys($appearance->schema->texts) as $key) {
            foreach (app(LanguageRegistry::class)->supported() as $locale) {
                $this->texts[$key][$locale] = $appearance->texts($key)[$locale] ?? '';
            }
        }
    }

    /**
     * @return array{values: array<string, string|bool>, texts: array<string, array<string, string>>}
     */
    protected function input(): array
    {
        return ['values' => $this->values, 'texts' => $this->texts];
    }

    protected function rejected(AppearanceRejected $e): void
    {
        $this->addError($e->field, MenuOptions::appearanceMessage($e));

        $this->notice = __('manager_appearance.errors.check_fields');
        $this->noticeTone = 'danger';
    }

    /**
     * Validates what is on screen and keeps it in THIS viewer's session for
     * the preview route to render — nothing is saved, nothing is live.
     */
    protected function stashPreview(string $page, AppearanceSchema $schema, string $event): void
    {
        $this->resetErrorBag();

        try {
            $appearance = Appearance::fromInput($schema, $this->input(), app(LanguageRegistry::class)->supported());
        } catch (AppearanceRejected $e) {
            $this->rejected($e);

            return;
        }

        session()->put(ManagerAppearancePreviewController::STASH.$page, $appearance->toArray());
        $this->notice = '';
        $this->dispatch($event);
    }

    /**
     * lang-tabs fields keyed `texts.<key>`, with their current values.
     *
     * @param  array<string, array{label: string, type?: string, rows?: int}>  $labels
     * @return array{fields: list<array<string, mixed>>, values: array<string, array<string, string>>}
     */
    protected function textFields(AppearanceSchema $schema, array $labels): array
    {
        $fields = [];
        $values = [];

        foreach ($labels as $key => $field) {
            $fields[] = [
                'name' => 'texts.'.$key,
                'label' => $field['label'],
                'type' => $field['type'] ?? 'input',
                'rows' => $field['rows'] ?? 3,
                'max' => $schema->texts[$key] ?? 200,
                'counter' => true,
            ];
            $values['texts.'.$key] = $this->texts[$key] ?? [];
        }

        return ['fields' => $fields, 'values' => $values];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    protected function previewLocales(): array
    {
        $languages = app(LanguageRegistry::class);

        return array_map(
            static fn (string $code): array => ['code' => $code, 'label' => $languages->shortLabel($code)],
            app(TenantLocales::class)->enabled(),
        );
    }

    protected function say(string $message): void
    {
        $this->notice = $message;
        $this->noticeTone = 'success';
    }

    protected function fail(string $message): void
    {
        $this->notice = $message;
        $this->noticeTone = 'danger';
    }

    protected function viewer(Permission $permission = Permission::AppearanceView): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission($permission), 403);

        return $user;
    }
}
