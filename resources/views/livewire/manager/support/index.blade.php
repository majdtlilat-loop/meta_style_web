{{--
    The center's own tickets with Meta Style (App\Livewire\Manager\Support\Index).
    Separate from customer conversations. Every row arrives presented.
--}}
<div class="stack support-desk">
    <x-ui.page-header :title="__('manager_support.title')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('manager_support.results', $tickets->total(), ['count' => number_format($tickets->total())]) }}</span>
        </x-slot:meta>
        @if($canManage)
            <x-slot:actions>
                <button class="button" type="button" wire:click="openCreate" wire:loading.attr="data-loading" wire:target="openCreate"><x-ui.icon name="plus" size="16" />{{ __('manager_support.actions.new') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash />

    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('manager_support.filters.label') }}">
        @foreach($filters as $value)
            <button type="button" wire:click="setStatus('{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}" wire:key="filter-{{ $value }}">
                {{ __('manager_support.filters.'.$value) }}
                <span class="segmented__count">{{ number_format($counts[$value] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="setStatus,gotoPage,nextPage,previousPage">
        @if($rows === [])
            <x-ui.empty-state icon="support" :title="$status === 'active' ? __('manager_support.empty.active_title') : __('manager_support.empty.title')">
                @if($canManage)
                    <button class="button button--sm" type="button" wire:click="openCreate"><x-ui.icon name="plus" size="16" />{{ __('manager_support.actions.new') }}</button>
                @endif
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('manager_support.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('manager_support.table.ticket') }}</th>
                        <th scope="col">{{ __('manager_support.table.priority') }}</th>
                        <th scope="col">{{ __('manager_support.table.status') }}</th>
                        <th scope="col">{{ __('manager_support.table.activity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="ticket-{{ $row['uuid'] }}">
                            <td data-label="{{ __('manager_support.table.ticket') }}" data-primary>
                                <a class="cell-title" href="{{ $row['href'] }}" wire:navigate>{{ $row['subject'] }}</a>
                                <span class="cell-sub"><span dir="ltr">{{ $row['reference'] }}</span> · {{ __('manager_support.table.opened_by', ['name' => $row['opened_by']]) }}</span>
                            </td>
                            <td data-label="{{ __('manager_support.table.priority') }}">
                                <x-ui.status :tone="$row['priority_tone']" :label="$row['priority_label']" :dot="false" />
                            </td>
                            <td data-label="{{ __('manager_support.table.status') }}">
                                <x-ui.status :value="$row['status']" :label="$row['status_label']" />
                            </td>
                            <td data-label="{{ __('manager_support.table.activity') }}">
                                @if($row['activity'])
                                    <time datetime="{{ $row['activity_iso'] }}" title="{{ $row['activity_full'] }}">{{ $row['activity'] }}</time>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $tickets->links() }}

    @if($creating)
        <x-ui.modal :title="__('manager_support.create.title')" icon="support" submit="create" close="closeCreate" size="lg">
            @if($error !== '')
                <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>
            @endif
            <div class="form-grid">
                <x-ui.field :label="__('manager_support.fields.subject')" for="support-subject" name="subject" required class="form-grid__full">
                    <input id="support-subject" wire:model="subject" maxlength="190" required autofocus>
                </x-ui.field>
                <x-ui.field :label="__('manager_support.fields.priority')" for="support-priority" name="priority" required>
                    <select id="support-priority" wire:model="priority">
                        @foreach($priorities as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            </div>
            <x-ui.field :label="__('manager_support.fields.message')" for="support-body" name="body" required>
                <textarea id="support-body" rows="6" wire:model="body" maxlength="5000" required placeholder="{{ __('manager_support.create.placeholder') }}"></textarea>
            </x-ui.field>
            @include('livewire.manager.support.attachments-field', ['inputId' => 'support-files'])
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeCreate">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="create,files"><x-ui.icon name="send" size="16" />{{ __('manager_support.create.submit') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
