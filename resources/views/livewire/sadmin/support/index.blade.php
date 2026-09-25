@php
    use App\View\Label;

    $priorityTone = ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'neutral', 'low' => 'neutral'];
    $statusTabs = ['active', 'open', 'in_progress', 'waiting_center', 'resolved', 'closed', 'all'];
    $activeFilters = collect([$priority, $center])->filter(fn ($value) => $value !== '')->count();
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_support.title')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('sadmin_support.results', $tickets->total(), ['count' => number_format($tickets->total())]) }}</span>
        </x-slot:meta>
        @if($canManage)
            <x-slot:actions>
                <button class="button" type="button" wire:click="openCreate"><x-ui.icon name="plus" size="16" />{{ __('sadmin_support.new') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash />

    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('sadmin_support.filters.status') }}">
        @foreach($statusTabs as $value)
            <button type="button" wire:click="setStatus('{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                {{ in_array($value, ['active', 'all'], true) ? __('sadmin_support.filters.'.$value) : Label::for('ticket_status', $value) }}
                <span class="segmented__count">{{ number_format($counts[$value] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <form class="filter-bar" role="search" aria-label="{{ __('sadmin_support.filters.label') }}" x-on:submit.prevent data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
        <div class="field filter-bar__search">
            <label for="support-search">{{ __('sadmin_support.filters.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="support-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('sadmin_support.filters.search_placeholder') }}" autocomplete="off">
            </div>
        </div>
        <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="support-priority">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
            @if($activeFilters > 0)<span class="badge">{{ $activeFilters }}</span>@endif
        </button>
        <div class="field">
            <label for="support-priority">{{ __('sadmin_support.filters.priority') }}</label>
            <select id="support-priority" wire:model.live="priority">
                <option value="">{{ __('sadmin_support.filters.all_priorities') }}</option>
                @foreach(\App\Livewire\Sadmin\Support\Index::PRIORITIES as $value)
                    <option value="{{ $value }}">{{ Label::for('ticket_priority', $value) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="support-center">{{ __('sadmin_support.filters.center') }}</label>
            <select id="support-center" wire:model.live="center">
                <option value="">{{ __('sadmin_support.filters.all_centers') }}</option>
                @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                @endforeach
            </select>
        </div>
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('sadmin_support.filters.clear') }}</button>
            </div>
        @endif
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,status,setStatus,priority,center,clearFilters,gotoPage,nextPage,previousPage">
        @if($tickets->isEmpty())
            <x-ui.empty-state :icon="$hasFilters ? 'filter' : 'check-circle'" :title="$hasFilters ? __('sadmin_support.empty.title') : __('sadmin_support.empty.none_title')" :description="$hasFilters ? __('sadmin_support.empty.description') : null">
                @if($hasFilters)<button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('sadmin_support.filters.clear') }}</button>@endif
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_support.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('sadmin_support.table.ticket') }}</th>
                        <th scope="col">{{ __('sadmin_support.table.center') }}</th>
                        <th scope="col">{{ __('sadmin_support.table.priority') }}</th>
                        <th scope="col">{{ __('sadmin_support.table.status') }}</th>
                        <th scope="col">{{ __('sadmin_support.table.activity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tickets as $ticket)
                        <tr wire:key="ticket-{{ $ticket->id }}">
                            <td data-label="{{ __('sadmin_support.table.ticket') }}" data-primary>
                                <a class="cell-title" href="{{ route('superadmin.support.show', $ticket->uuid) }}" wire:navigate>{{ $ticket->subject }}</a>
                                <span class="cell-sub"><span dir="ltr">{{ $ticket->reference }}</span> · {{ __('sadmin_support.table.opened_by', ['name' => $ticket->created_by_label]) }}</span>
                            </td>
                            <td data-label="{{ __('sadmin_support.table.center') }}">
                                @if($ticket->tenant)
                                    <a class="cell-link" href="{{ route('superadmin.centers.show', ['tenant' => $ticket->tenant_id, 'tab' => 'support']) }}" wire:navigate>{{ $ticket->tenant->name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="{{ __('sadmin_support.table.priority') }}">
                                <x-ui.status :tone="$priorityTone[$ticket->priority] ?? 'neutral'" :label="Label::for('ticket_priority', $ticket->priority)" :dot="false" />
                            </td>
                            <td data-label="{{ __('sadmin_support.table.status') }}">
                                <x-ui.status :value="$ticket->status" :label="Label::for('ticket_status', $ticket->status)" />
                            </td>
                            <td data-label="{{ __('sadmin_support.table.activity') }}">
                                <time datetime="{{ $ticket->last_activity_at?->toIso8601String() }}" title="{{ $ticket->last_activity_at?->translatedFormat('j M Y, H:i') }}">{{ $ticket->last_activity_at?->diffForHumans() ?? '—' }}</time>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $tickets->links() }}

    @if($creating)
        <x-ui.modal :title="__('sadmin_support.create.title')" icon="support" submit="create" close="closeCreate" size="lg">
            <div class="form-grid">
                <x-ui.field :label="__('sadmin_support.create.center')" for="ticket-center" name="tenantId" required>
                    <select id="ticket-center" wire:model="tenantId" required>
                        <option value="">{{ __('sadmin_support.create.choose_center') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_support.create.priority')" for="ticket-priority" name="newPriority" required>
                    <select id="ticket-priority" wire:model="newPriority">
                        @foreach(\App\Livewire\Sadmin\Support\Index::PRIORITIES as $value)
                            <option value="{{ $value }}">{{ Label::for('ticket_priority', $value) }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            </div>
            <x-ui.field :label="__('sadmin_support.create.subject')" for="ticket-subject" name="subject" required>
                <input id="ticket-subject" wire:model="subject" maxlength="190" required>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_support.create.message')" for="ticket-body" name="body" required>
                <textarea id="ticket-body" rows="5" wire:model="body" maxlength="5000" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeCreate">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="create">{{ __('sadmin_support.create.submit') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
