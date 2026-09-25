{{--
    Customer tags: add, rename, archive, restore — one name per enabled content
    language. Archived tags leave the filters and the form; customers keep them.
--}}
<div>
    @if($open && $canManage)
        <x-ui.drawer :title="__('manager_customers.tags.title')" close="close">
            <div class="stack">
                <x-ui.notice :message="$notice" :tone="$noticeTone" />

                <form class="card crm-tag-form" wire:submit="saveTag">
                    <div class="card__body stack stack--sm">
                        <x-ui.lang-tabs id="customer-tag" :locales="$locales" :primary="$primaryLocale" :values="['tagName' => $tagName]" :fields="[
                            ['name' => 'tagName', 'label' => $editingTag ? __('manager_customers.tags.rename') : __('manager_customers.tags.new'), 'max' => 60, 'required' => true],
                        ]" />
                    </div>
                    <footer class="card__footer">
                        @if($editingTag)
                            <button class="button button--secondary button--sm" type="button" wire:click="cancelRename">{{ __('ui.actions.cancel') }}</button>
                        @endif
                        <button class="button button--sm" type="submit" wire:loading.attr="data-loading" wire:target="saveTag">
                            <x-ui.icon :name="$editingTag ? 'save' : 'plus'" size="16" />{{ $editingTag ? __('ui.actions.save_changes') : __('manager_customers.tags.add') }}
                        </button>
                    </footer>
                </form>

                @if($tags === [])
                    <x-ui.empty-state compact icon="tag" :title="__('manager_customers.tags.none')" />
                @else
                    <ul class="row-list row-list--boxed">
                        @foreach($tags as $tag)
                            <li class="row-list__item" wire:key="tag-{{ $tag['uuid'] }}" @if($tag['archived']) data-muted="true" @endif>
                                <span class="row-list__icon" aria-hidden="true"><x-ui.icon name="tag" /></span>
                                <div class="row-list__body">
                                    <span class="cell-title">{{ $tag['name'] }} @if($tag['archived'])<span class="chip chip--muted">{{ __('manager_customers.status.archived') }}</span>@endif</span>
                                    <span class="cell-sub">{{ trans_choice('manager_customers.tags.customers', $tag['customers'], ['n' => number_format($tag['customers'])]) }}</span>
                                </div>
                                <span class="cluster cluster--tight">
                                    @if($tag['archived'])
                                        <button class="button button--ghost button--sm" type="button" wire:click="restoreTag('{{ $tag['uuid'] }}')"><x-ui.icon name="undo" size="16" />{{ __('ui.actions.restore') }}</button>
                                    @else
                                        <button class="icon-button icon-button--sm" type="button" wire:click="rename('{{ $tag['uuid'] }}')" aria-label="{{ __('manager_customers.tags.rename_named', ['name' => $tag['name']]) }}" title="{{ __('ui.actions.edit') }}"><x-ui.icon name="edit" /></button>
                                        <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="archiveTag('{{ $tag['uuid'] }}')" wire:confirm="{{ __('manager_customers.tags.archive_body', ['name' => $tag['name']]) }}" data-confirm-title="{{ __('manager_customers.tags.archive_title') }}" data-confirm-label="{{ __('ui.actions.archive') }}" data-confirm-tone="danger" aria-label="{{ __('manager_customers.tags.archive_title') }}" title="{{ __('ui.actions.archive') }}"><x-ui.icon name="archive" /></button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-ui.drawer>
    @endif
</div>
