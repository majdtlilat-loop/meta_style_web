{{--
    The category drawer: name and description per ENABLED content language,
    menu visibility, one image, archive. A category groups the MENU only; it
    routes no work (that is a department, ADR-037).
--}}
<div>
    @if($open)
        <x-ui.drawer close="close" submit="save" :title="$title">
            <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.editor.sections.details') }}</h3>
                <x-ui.lang-tabs id="category-text" :fields="$textFields" :values="$textValues" :locales="$locales" :primary="$primaryLocale" />
            </section>

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.editor.sections.availability') }}</h3>
                <label class="choice choice--switch">
                    <input type="checkbox" role="switch" wire:model="categoryActive">
                    <span>{{ __('manager_catalog.fields.active') }}<small class="field-help">{{ __('manager_catalog.category_editor.active_help') }}</small></span>
                </label>
                <label class="choice choice--switch">
                    <input type="checkbox" role="switch" wire:model="categoryPublic">
                    <span>{{ __('manager_catalog.fields.public') }}</span>
                </label>
            </section>

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.category_editor.image') }}</h3>
                @if($editingCategory)
                    <livewire:center.catalog.media-gallery owner="service_category" :owner-uuid="$editingCategory" :key="'category-gallery-'.$editingCategory" />
                @else
                    <p class="catalog-editor__note"><x-ui.icon name="image" size="16" />{{ __('manager_catalog.category_editor.image_after_save') }}</p>
                @endif
            </section>

            @if($editingCategory && $canSave)
                <section class="danger-zone">
                    <div>
                        <h3>{{ __('manager_catalog.categories.archive_title') }}</h3>
                        <p>{{ __('manager_catalog.category_editor.archive_hint') }}</p>
                    </div>
                    <button class="button button--danger-soft" type="button" wire:click="archive"
                        wire:confirm="{{ __('manager_catalog.category_editor.archive_confirm') }}"
                        data-confirm-title="{{ __('manager_catalog.categories.archive_title') }}" data-confirm-tone="danger">
                        <x-ui.icon name="archive" size="16" />{{ __('ui.actions.archive') }}
                    </button>
                </section>
            @endif

            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="close">{{ $editingCategory ? __('ui.actions.close') : __('ui.actions.cancel') }}</button>
                @if($canSave)
                    <button class="button" type="submit" wire:loading.attr="disabled" wire:target="save">
                        <x-ui.icon name="save" size="16" />{{ $editingCategory ? __('ui.actions.save_changes') : __('manager_catalog.category_editor.create') }}
                    </button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
