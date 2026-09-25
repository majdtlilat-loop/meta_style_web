<div>
    <x-ui.card :title="__('manager_site.history.title')" flush>
        @error('restore')
            <div class="card__body"><div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div></div>
        @enderror
        @if($versions === [])
            <x-ui.empty-state compact icon="history" :title="__('manager_site.history.empty')" />
        @else
            <ul class="row-list">
                @foreach($versions as $version)
                    <li class="row-list__item" wire:key="site-version-{{ $version['uuid'] }}">
                        <div class="row-list__body">
                            <span class="cell-title">
                                {{ __('manager_site.history.version', ['version' => $version['version']]) }}
                                @if($version['live'])<x-ui.status value="published" :label="__('manager_site.history.live')" :dot="false" />@endif
                            </span>
                            <span class="cell-sub">{{ $version['at'] ?? '—' }}@if($version['by']) · {{ $version['by'] }}@endif</span>
                        </div>
                        @if($canManage)
                            <button class="button button--secondary button--sm" type="button" wire:click="restore('{{ $version['uuid'] }}')" wire:loading.attr="data-loading" wire:target="restore('{{ $version['uuid'] }}')"
                                wire:confirm="{{ __('manager_site.history.restore_confirm', ['version' => $version['version']]) }}" data-confirm-title="{{ __('manager_site.history.restore_title') }}">
                                <x-ui.icon name="undo" size="16" />{{ __('manager_site.history.restore') }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
