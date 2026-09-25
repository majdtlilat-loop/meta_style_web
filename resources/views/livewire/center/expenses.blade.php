{{--
    Expenses: real money the center paid out.

    docs/20-FINANCE.md §§38–41. Posted, then voided with a reason if wrong —
    never edited, never deleted. Totals are the Finance module's, per currency;
    labels and branch-local dates arrive ready from the component.
--}}
<div class="stack pos-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.expenses')">
        @if($canRecord)
            <x-slot:actions>
                <button class="button button--secondary" type="button" wire:click="openCategories"><x-ui.icon name="tag" size="16" />{{ __('Expense categories') }}</button>
                <button class="button" type="button" wire:click="openForm"><x-ui.icon name="plus" size="16" />{{ __('Record an expense') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.center.finance.tabs')

    @if(! $owned && $offer !== null)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($denied)
        <x-ui.empty-state icon="lock" :title="__('manager_finance.denied.title')" :description="__('manager_finance.expenses.denied')" />
    @else
        @if($error !== '' && ! $showForm && $error !== $listError)<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
        @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif
        @if($listError !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $listError }}</p></div>@endif

        <form class="filter-bar pos-filters" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}">
            @if(count($branches) > 1)
                <div class="field">
                    <label for="expense-branch">{{ __('Branch') }}</label>
                    <select id="expense-branch" wire:model.live="branch">
                        @foreach($branches as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="field">
                <label for="expense-filter-category">{{ __('Category') }}</label>
                <select id="expense-filter-category" wire:model.live="categoryFilter">
                    <option value="">{{ __('manager_finance.expenses.all_categories') }}</option>
                    @foreach($categories as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="expense-filter-method">{{ __('How') }}</label>
                <select id="expense-filter-method" wire:model.live="methodFilter">
                    <option value="">{{ __('manager_finance.receipts.all_methods') }}</option>
                    @foreach($methods as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="expense-filter-status">{{ __('Status') }}</label>
                <select id="expense-filter-status" wire:model.live="statusFilter">
                    <option value="">{{ __('manager_finance.receipts.all_statuses') }}</option>
                    <option value="posted">{{ __('manager_finance.expense_status.posted') }}</option>
                    <option value="voided">{{ __('manager_finance.expense_status.voided') }}</option>
                </select>
            </div>
            @if($hasFilters)
                <div class="filter-bar__actions">
                    <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
                </div>
            @endif
        </form>

        @include('livewire.center.finance.window')

        @if($totals !== null)
            <div class="stat-grid" wire:loading.class="is-refreshing">
                <x-ui.stat icon="expenses" :label="__('manager_finance.expenses.posted_total')" :value="$totals['posted']" :hint="trans_choice('manager_finance.expenses.count', $totals['posted_count'], ['count' => $totals['posted_count']])">
                    @if($totals['by_category'] !== [])
                        <dl class="stat__breakdown">
                            @foreach($totals['by_category'] as $row)
                                <div><dt>{{ $row['name'] }}</dt><dd dir="ltr">{{ $row['amount'] }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </x-ui.stat>
                <x-ui.stat icon="x-circle" :label="__('manager_finance.expenses.voided_total')" :value="(string) $totals['voided_count']" />
            </div>
            @if($totals['mixed'])<p class="field-help">{{ __('manager_finance.currency_mixed') }}</p>@endif
        @endif

        <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="branch,categoryFilter,methodFilter,statusFilter,setRange,from,until,gotoPage,nextPage,previousPage,clearFilters">
            @if($expenses === [])
                <x-ui.empty-state :icon="$hasFilters ? 'filter' : 'expenses'" :title="$hasFilters ? __('manager_finance.expenses.no_match') : __('No expenses in this period.')">
                    @if($hasFilters)
                        <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
                    @elseif($canRecord)
                        <button class="button button--sm" type="button" wire:click="openForm">{{ __('Record an expense') }}</button>
                    @endif
                </x-ui.empty-state>
            @else
                <table>
                    <caption class="sr-only">{{ __('Expenses') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Description') }}</th>
                            <th scope="col">{{ __('Category') }}</th>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col" class="numeric">{{ __('Amount') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($expenses as $expense)
                            <tr wire:key="expense-{{ $expense['uuid'] }}" @unless($expense['posted']) data-muted="true" @endunless>
                                <td data-label="{{ __('Description') }}" data-primary>
                                    <span class="cell-title">{{ $expense['description'] }}</span>
                                    <span class="cell-sub">{{ $expense['method_label'] }}@if($expense['from_drawer']) · {{ __('from drawer') }}@endif @if($expense['payee']) · {{ $expense['payee'] }}@endif @if($expense['reference']) · <span dir="ltr">{{ $expense['reference'] }}</span>@endif</span>
                                </td>
                                <td data-label="{{ __('Category') }}">{{ $expense['category']['name'] ?? '—' }}</td>
                                <td data-label="{{ __('When') }}" class="nowrap"><time datetime="{{ $expense['occurred_at'] }}">{{ $expense['when'] }}</time><span class="cell-sub">{{ $expense['created_by'] }}</span></td>
                                <td data-label="{{ __('Amount') }}" class="numeric"><span dir="ltr" class="tabular">{{ $expense['amount']['formatted'] }}</span></td>
                                <td data-label="{{ __('Status') }}">
                                    <x-ui.status :value="$expense['posted'] ? 'active' : 'void'" :label="$expense['status_label']" />
                                    @if($expense['void_reason'] !== null)<span class="cell-sub">{{ $expense['void_reason'] }} · {{ $expense['voided_by'] }}</span>@endif
                                </td>
                                <td class="actions">
                                    @if($expense['posted'] && $canRecord)
                                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="$set('voiding', '{{ $expense['uuid'] }}')">{{ __('Void') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if($paginator !== null)
            {{ $paginator->links() }}
        @endif
    @endif

    @if($voiding !== '' && $canRecord)
        <x-ui.modal :title="__('Void expense')" :description="__('manager_finance.expenses.void_help')" icon="x-circle" tone="danger" submit="void" close="$set('voiding', '')">
            @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
            <x-ui.field :label="__('Reason for voiding')" for="void-reason" name="voidReason" required>
                <input id="void-reason" type="text" wire:model="voidReason" maxlength="190" required autocomplete="off">
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('voiding', '')">{{ __('Cancel') }}</button>
                <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="void">{{ __('Void expense') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($showForm && $canRecord)
        @include('livewire.center.finance.expense-form')
    @endif

    @if($showCategories && $canRecord)
        @include('livewire.center.finance.expense-categories')
    @endif
</div>
