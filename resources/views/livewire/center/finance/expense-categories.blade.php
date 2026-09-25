{{--
    Expense categories: the center's own vocabulary. Renamed one field per
    enabled content language; archived, never deleted — past expenses keep them.
--}}
<x-ui.drawer :title="__('Expense categories')" close="$toggle('showCategories')" size="sm">
    <div class="stack">
        @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
        @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif

        <form class="cluster" wire:submit="addCategory">
            <label class="sr-only" for="new-category">{{ __('New category') }}</label>
            <input id="new-category" type="text" wire:model="categoryName" placeholder="{{ __('New category') }}" maxlength="80" required class="grow">
            <button class="button button--secondary" type="submit" wire:loading.attr="data-loading" wire:target="addCategory"><x-ui.icon name="plus" size="16" />{{ __('Add category') }}</button>
        </form>

        <ul class="row-list pos-categories">
            @forelse($allCategories as $option)
                <li class="row-list__item" wire:key="category-{{ $option['uuid'] }}" @if($option['archived']) data-muted="true" @endif>
                    @if($editingCategory === $option['uuid'])
                        <form class="stack stack--sm grow" wire:submit="saveCategory">
                            @foreach($categoryLocales as $field)
                                <x-ui.field :label="$field['label']" for="category-name-{{ $field['locale'] }}" name="categoryNames.{{ $field['locale'] }}" :required="$field['primary']">
                                    <input id="category-name-{{ $field['locale'] }}" type="text" dir="{{ $field['dir'] }}" lang="{{ $field['locale'] }}" wire:model="categoryNames.{{ $field['locale'] }}" maxlength="80" @required($field['primary'])>
                                </x-ui.field>
                            @endforeach
                            <div class="cluster">
                                <button class="button button--sm" type="submit" wire:loading.attr="data-loading" wire:target="saveCategory">{{ __('ui.actions.save') }}</button>
                                <button class="button button--secondary button--sm" type="button" wire:click="cancelCategoryEdit">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    @else
                        <div class="row-list__body">
                            <span class="cell-title">{{ $option['name'] }}</span>
                            @if($option['archived'])<span class="cell-sub">{{ __('ui.states.archived') }}</span>@endif
                        </div>
                        <div class="cluster cluster--tight">
                            @if($option['archived'])
                                <button class="button button--ghost button--sm" type="button" wire:click="restoreCategory('{{ $option['uuid'] }}')">{{ __('ui.actions.restore') }}</button>
                            @else
                                <button class="icon-button icon-button--sm" type="button" wire:click="editCategory('{{ $option['uuid'] }}')" aria-label="{{ __('manager_finance.expenses.rename', ['name' => $option['name']]) }}" title="{{ __('ui.actions.edit') }}"><x-ui.icon name="edit" /></button>
                                <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="archiveCategory('{{ $option['uuid'] }}')" wire:confirm="{{ __('manager_finance.expenses.archive_confirm', ['name' => $option['name']]) }}" data-confirm-title="{{ __('ui.actions.archive') }}" data-confirm-tone="danger" aria-label="{{ __('ui.actions.archive') }}" title="{{ __('ui.actions.archive') }}"><x-ui.icon name="archive" /></button>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="row-list__item"><span class="muted">{{ __('None yet.') }}</span></li>
            @endforelse
        </ul>

        @if($archivedCount > 0)
            <label class="choice"><input type="checkbox" wire:model.live="showArchivedCategories"><span>{{ trans_choice('manager_finance.expenses.show_archived', $archivedCount, ['count' => $archivedCount]) }}</span></label>
        @endif
    </div>
</x-ui.drawer>
