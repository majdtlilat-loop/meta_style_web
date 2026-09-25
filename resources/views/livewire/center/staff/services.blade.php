<div class="stack staff-services">
    @if(! $found)
        <x-ui.empty-state compact icon="user-x" :title="__('manager_staff.profile.missing_title')" />
    @else
        <x-ui.notice :message="$notice" dismiss="dismissNotice" />

        @if($total === 0)
            <x-ui.empty-state compact icon="scissors" :title="__('manager_staff.services.empty_title')" :description="__('manager_staff.services.empty_body')" />
        @else
            <div class="staff-services__toolbar">
                <div class="search-input">
                    <x-ui.icon name="search" />
                    <input type="search" wire:model.live.debounce.250ms="filter" placeholder="{{ __('manager_staff.services.search') }}" aria-label="{{ __('manager_staff.services.search') }}" autocomplete="off">
                </div>
                <span class="muted tabular">{{ __('manager_staff.services.selected', ['count' => count($selected), 'total' => $total]) }}</span>
                @if($canEdit)
                    <button class="text-button" type="button" wire:click="selectAll({{ count($selected) === $total ? 'false' : 'true' }})">{{ count($selected) === $total ? __('ui.actions.clear') : __('manager_staff.services.select_all') }}</button>
                @endif
            </div>

            @error('selected')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror

            @if($services === [])
                <p class="muted">{{ __('ui.empty.no_results') }}</p>
            @else
                <ul class="staff-services__list" role="list">
                    @foreach($services as $service)
                        <li wire:key="svc-{{ $service['uuid'] }}">
                            <label class="choice staff-services__item">
                                <input type="checkbox" value="{{ $service['uuid'] }}" wire:model="selected" @disabled(! $canEdit)>
                                <span class="staff-services__name">{{ $service['name'] }}</span>
                                <span class="staff-services__meta">
                                    <span class="muted tabular">{{ trans_choice('manager_staff.services.minutes', $service['minutes'], ['count' => $service['minutes']]) }}</span>
                                    @if($service['archived'])<span class="chip chip--muted">{{ __('ui.states.archived') }}</span>@elseif($service['inactive'])<span class="chip chip--muted">{{ __('ui.states.inactive') }}</span>@endif
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($canEdit)
                <div class="cluster cluster--end">
                    <button class="button" type="button" wire:click="save" wire:loading.attr="data-loading" wire:target="save">{{ __('manager_staff.services.save') }}</button>
                </div>
            @else
                <p class="field-help"><x-ui.icon name="lock" size="14" class="inline" /> {{ __('manager_staff.services.read_only') }}</p>
            @endif
        @endif
    @endif
</div>
