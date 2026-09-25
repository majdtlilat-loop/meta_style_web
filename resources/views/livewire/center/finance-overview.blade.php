{{--
    Center finance: billed and money moved, kept apart.

    docs/20-FINANCE.md §§42–44. "Invoiced" is what was billed; "collected" is money
    received. An unpaid invoice is never shown as money. Figures, tones, labels
    and local times arrive ready; this view compares and adds nothing.
--}}
<div class="stack pos-page">
    <x-ui.page-header :title="__('Center finance')">
        @if($reports !== [])
            <x-slot:actions>
                @foreach($reports as $report)
                    <a class="button button--ghost" href="{{ $report['href'] }}" wire:navigate><x-ui.icon name="reports" size="16" />{{ $report['label'] }}</a>
                @endforeach
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.center.finance.tabs')

    @if(! $owned && $offer !== null)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($error !== '' && $owned)<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif

    <form class="filter-bar pos-filters" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}">
        <div class="field">
            <label for="finance-branch">{{ __('Branch') }}</label>
            <select id="finance-branch" wire:model.live="branch">
                @if($owned)<option value="">{{ __('All my branches') }}</option>@else<option value="">{{ __('manager_finance.overview.choose_branch') }}</option>@endif
                @foreach($branches as $option)
                    <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @include('livewire.center.finance.window')

    @if($summary !== null)
        <div class="stat-grid" wire:loading.class="is-refreshing" wire:target="branch,setRange,from,until">
            <x-ui.stat icon="receipt" :label="__('Invoiced')" :value="$summary['invoiced']" :hint="trans_choice(':count invoice|:count invoices', $summary['invoice_count'], ['count' => $summary['invoice_count']])">
                @if($summary['has_voided'])
                    <p class="stat__hint">{{ __('manager_finance.overview.voided', ['amount' => $summary['voided']]) }}</p>
                @endif
            </x-ui.stat>
            <x-ui.stat icon="wallet" :label="__('Collected')" :value="$summary['collected']">
                <dl class="stat__breakdown">
                    @foreach($summary['collected_by_method'] as $row)
                        <div @if($row['zero']) class="muted" @endif><dt>{{ $row['label'] }}</dt><dd dir="ltr">{{ $row['amount'] }}</dd></div>
                    @endforeach
                </dl>
            </x-ui.stat>
            <x-ui.stat icon="undo" :label="__('Refunded')" :value="$summary['refunded']">
                <dl class="stat__breakdown">
                    @foreach($summary['refunded_by_method'] as $row)
                        <div @if($row['zero']) class="muted" @endif><dt>{{ $row['label'] }}</dt><dd dir="ltr">{{ $row['amount'] }}</dd></div>
                    @endforeach
                </dl>
            </x-ui.stat>
            <x-ui.stat icon="expenses" :label="__('Expenses')" :value="$summary['expenses']" :hint="$summary['expense_reversals'] !== null ? __('manager_finance.overview.reversals', ['amount' => $summary['expense_reversals']]) : null" />
            <x-ui.stat icon="coins" :label="__('Net money movement')" :value="$summary['net']" :hint="__('Collected − refunded − expenses')" :tone="$summary['net_tone']" />
            <x-ui.stat icon="clock" :label="__('Still owed')" :value="$summary['outstanding']" :hint="__('On invoices issued in this period')" :tone="$summary['outstanding_tone']" />
            <x-ui.stat icon="wallet" :label="__('manager_finance.overview.drawer_difference')" :value="$summary['variance']" :hint="__('manager_finance.overview.drawer_difference_hint')" :tone="$summary['variance_tone']" />
        </div>

        @if($summary['by_branch'] !== [])
            <x-ui.card :title="__('By branch')" flush>
                <div class="table-shell table-shell--stack">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Branch') }}</th>
                                <th scope="col" class="numeric">{{ __('Invoiced') }}</th>
                                <th scope="col" class="numeric">{{ __('Collected') }}</th>
                                <th scope="col" class="numeric">{{ __('Refunded') }}</th>
                                <th scope="col" class="numeric">{{ __('Expenses') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($summary['by_branch'] as $row)
                                <tr wire:key="branch-{{ $row['branch'] }}">
                                    <td data-label="{{ __('Branch') }}" data-primary><button class="cell-title text-button" type="button" wire:click="$set('branch', '{{ $row['branch'] }}')">{{ $row['name'] }}</button></td>
                                    <td data-label="{{ __('Invoiced') }}" class="numeric" dir="ltr">{{ $row['invoiced'] }}</td>
                                    <td data-label="{{ __('Collected') }}" class="numeric" dir="ltr">{{ $row['collected'] }}</td>
                                    <td data-label="{{ __('Refunded') }}" class="numeric" dir="ltr">{{ $row['refunded'] }}</td>
                                    <td data-label="{{ __('Expenses') }}" class="numeric" dir="ltr">{{ $row['expenses'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Drawer counts')" flush>
            <x-slot:actions>
                @if($shiftsLink !== null)<a class="button button--ghost button--sm" href="{{ $shiftsLink }}" wire:navigate>{{ __('manager_finance.overview.all_shifts') }}</a>@endif
            </x-slot:actions>
            @if($summary['variances'] === [])
                <x-ui.empty-state compact icon="wallet" :title="__('No drawer counted in this period.')" />
            @else
                <div class="table-shell table-shell--stack">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Cashier') }}</th>
                                <th scope="col">{{ __('manager_finance.overview.counted_at') }}</th>
                                <th scope="col" class="numeric">{{ __('Expected') }}</th>
                                <th scope="col" class="numeric">{{ __('Counted') }}</th>
                                <th scope="col" class="numeric">{{ __('Difference') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($summary['variances'] as $row)
                                <tr wire:key="variance-{{ $row['shift'] }}">
                                    <td data-label="{{ __('Cashier') }}" data-primary><span class="cell-title">{{ $row['cashier'] }}</span></td>
                                    <td data-label="{{ __('manager_finance.overview.counted_at') }}" class="nowrap">{{ $row['when'] }}</td>
                                    <td data-label="{{ __('Expected') }}" class="numeric" dir="ltr">{{ $row['expected'] }}</td>
                                    <td data-label="{{ __('Counted') }}" class="numeric" dir="ltr">{{ $row['counted'] }}</td>
                                    <td data-label="{{ __('Difference') }}" class="numeric" dir="ltr"><span class="text-{{ $row['tone'] }}">{{ $row['variance'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    @if($branch !== '')
        <x-ui.card :title="__('Money movements')" flush>
            @if($entries === [])
                <x-ui.empty-state compact icon="coins" :title="__('No money moved in this period.')" />
            @else
                <div class="table-shell table-shell--stack">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('When') }}</th>
                                <th scope="col">{{ __('What') }}</th>
                                <th scope="col">{{ __('How') }}</th>
                                <th scope="col" class="numeric">{{ __('In') }}</th>
                                <th scope="col" class="numeric">{{ __('Out') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($entries as $entry)
                                <tr wire:key="entry-{{ $entry['uuid'] }}">
                                    <td data-label="{{ __('When') }}" class="nowrap"><time datetime="{{ $entry['occurred_at'] }}">{{ $entry['when'] }}</time></td>
                                    <td data-label="{{ __('What') }}" data-primary>
                                        <span class="cell-title">{{ $entry['kind_label'] }}@if($entry['reference'] !== null) · <span dir="auto">{{ $entry['reference'] }}</span>@endif</span>
                                        @if($entry['detail'] !== null)<span class="cell-sub">{{ $entry['detail'] }}</span>@endif
                                    </td>
                                    <td data-label="{{ __('How') }}">{{ $entry['method_label'] }}</td>
                                    <td data-label="{{ __('In') }}" class="numeric" dir="ltr">@if($entry['incoming'])<span class="text-success">{{ $entry['amount']['formatted'] }}</span>@endif</td>
                                    <td data-label="{{ __('Out') }}" class="numeric" dir="ltr">@unless($entry['incoming'])<span class="text-danger">{{ $entry['amount']['formatted'] }}</span>@endunless</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($ledgerCapped)
                    <p class="field-help pos-card-note">{{ __('manager_finance.overview.ledger_capped') }}</p>
                @endif
            @endif
        </x-ui.card>
    @elseif(! $owned)
        <x-ui.empty-state icon="finance" :title="__('manager_finance.overview.choose_branch_title')" />
    @endif
</div>
