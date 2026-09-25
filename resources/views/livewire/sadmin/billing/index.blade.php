@php
    use App\View\Label;

    $locale = app()->getLocale();
    $fmt = fn (int $minor, ?string $currency): string => $money->format($minor, (string) $currency, $locale);
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y') : '—';
    $effective = fn ($invoice) => $invoice->isOverdue() ? 'overdue' : $invoice->status;
    $planName = fn ($invoice) => is_array($invoice->plan_name_snapshot) ? ($invoice->plan_name_snapshot[$locale] ?? $invoice->plan_name_snapshot['en'] ?? null) : null;
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_billing.title')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('sadmin_billing.results', $invoices->total(), ['count' => number_format($invoices->total())]) }}</span>
        </x-slot:meta>
        @if($canManage)
            <x-slot:actions>
                <button class="button" type="button" wire:click="openIssue"><x-ui.icon name="plus" size="16" />{{ __('sadmin_billing.issue') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash />

    <div class="stat-grid">
        <x-ui.stat :label="__('sadmin_billing.summary.outstanding')" icon="wallet"
            :value="$summary['outstanding']->isEmpty() ? '0' : $summary['outstanding']->map(fn (array $row) => $fmt($row['minor'], $row['currency']))->join(' · ')"
            :hint="$summary['outstanding']->isEmpty() ? __('sadmin_billing.summary.outstanding_none') : null" />
        <x-ui.stat :label="__('sadmin_billing.summary.overdue')" icon="alert-triangle" :tone="$summary['overdue'] > 0 ? 'danger' : null" :value="number_format($summary['overdue'])" />
        <x-ui.stat :label="__('sadmin_billing.summary.collected')" icon="coins"
            :value="$summary['collected']->isEmpty() ? '0' : $summary['collected']->map(fn (array $row) => $fmt($row['minor'], $row['currency']))->join(' · ')"
            :hint="$summary['collected']->isEmpty() ? __('sadmin_billing.summary.collected_none') : null" />
    </div>

    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('sadmin_billing.filters.status') }}">
        <button type="button" wire:click="setStatus('')" aria-pressed="{{ $status === '' ? 'true' : 'false' }}">{{ __('sadmin_billing.filters.all') }}</button>
        @foreach(\App\Livewire\Sadmin\Billing\Index::FILTERS as $value)
            <button type="button" wire:click="setStatus('{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">{{ __('sadmin_billing.filters.'.$value) }}</button>
        @endforeach
    </div>

    <form class="filter-bar" role="search" aria-label="{{ __('sadmin_billing.filters.label') }}" x-on:submit.prevent>
        <div class="field filter-bar__search">
            <label for="billing-search">{{ __('sadmin_billing.filters.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="billing-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('sadmin_billing.filters.search_placeholder') }}" autocomplete="off">
            </div>
        </div>
        <div class="field">
            <label for="billing-cycle">{{ __('sadmin_billing.fields.cycle') }}</label>
            <select id="billing-cycle" wire:model.live="cycle">
                <option value="">{{ __('sadmin_billing.filters.all_cycles') }}</option>
                @foreach(['monthly', 'yearly'] as $value)<option value="{{ $value }}">{{ Label::for('billing_period', $value) }}</option>@endforeach
            </select>
        </div>
        @if($centerName || $hasFilters)
            <div class="filter-bar__actions">
                @if($centerName)
                    <span class="filter-chip">{{ __('sadmin_billing.filters.center', ['name' => $centerName]) }}
                        <button type="button" wire:click="$set('center', '')" aria-label="{{ __('sadmin_billing.filters.remove_center') }}"><x-ui.icon name="close" size="12" /></button>
                    </span>
                @endif
                @if($hasFilters)
                    <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('sadmin_billing.filters.clear') }}</button>
                @endif
            </div>
        @endif
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,status,setStatus,center,cycle,clearFilters,gotoPage,nextPage,previousPage">
        @if($invoices->isEmpty())
            <x-ui.empty-state :icon="$hasFilters ? 'filter' : 'receipt'" :title="$hasFilters ? __('sadmin_billing.empty.title') : __('sadmin_billing.empty.none_title')">
                @if($hasFilters)
                    <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('sadmin_billing.filters.clear') }}</button>
                @elseif($canManage)
                    <button class="button button--sm" type="button" wire:click="openIssue">{{ __('sadmin_billing.issue') }}</button>
                @endif
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_billing.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('sadmin_billing.table.invoice') }}</th>
                        <th scope="col">{{ __('sadmin_billing.table.center') }}</th>
                        <th scope="col">{{ __('sadmin_billing.fields.cycle') }}</th>
                        <th scope="col">{{ __('sadmin_billing.table.status') }}</th>
                        <th scope="col" class="numeric">{{ __('sadmin_billing.table.total') }}</th>
                        <th scope="col" class="numeric">{{ __('sadmin_billing.table.balance') }}</th>
                        <th scope="col">{{ __('sadmin_billing.table.due') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        @php
                            $state = $effective($invoice);
                            $balance = $invoice->balance();
                            $late = $state === 'overdue' ? (int) $invoice->due_at->diffInDays($now) : 0;
                        @endphp
                        <tr wire:key="invoice-{{ $invoice->id }}">
                            <td data-label="{{ __('sadmin_billing.table.invoice') }}" data-primary>
                                <button class="cell-title text-button" type="button" wire:click="showInvoice({{ $invoice->id }})" dir="ltr">{{ $invoice->number }}</button>
                                <span class="cell-sub">{{ $date($invoice->issued_at) }}@if($invoice->reference) · <span dir="ltr">{{ $invoice->reference }}</span>@endif</span>
                            </td>
                            <td data-label="{{ __('sadmin_billing.table.center') }}">
                                @if($invoice->tenant)
                                    <a class="cell-link" href="{{ route('superadmin.centers.show', ['tenant' => $invoice->tenant_id, 'tab' => 'billing']) }}" wire:navigate>{{ $invoice->tenant->name }}</a>
                                    @if($planName($invoice))<span class="cell-sub">{{ $planName($invoice) }}</span>@endif
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="{{ __('sadmin_billing.fields.cycle') }}">
                                {{ $invoice->billing_period ? __('sadmin_billing.cycle_label.'.$invoice->billing_period) : '—' }}
                                @if($invoice->period_start)<span class="cell-sub">{{ $date($invoice->period_start) }} – {{ $date($invoice->period_end) }}</span>@endif
                            </td>
                            <td data-label="{{ __('sadmin_billing.table.status') }}">
                                <x-ui.status :value="$state" :label="Label::for('invoice_status', $state)" />
                            </td>
                            <td data-label="{{ __('sadmin_billing.table.total') }}" class="numeric"><span dir="ltr">{{ $fmt($invoice->total_minor, $invoice->currency) }}</span></td>
                            <td data-label="{{ __('sadmin_billing.table.balance') }}" class="numeric">
                                <span dir="ltr" @class(['text-danger' => $state === 'overdue', 'muted' => $balance === 0])>{{ $fmt($balance, $invoice->currency) }}</span>
                            </td>
                            <td data-label="{{ __('sadmin_billing.table.due') }}">
                                {{ $date($invoice->due_at) }}
                                @if($late > 0)<span class="cell-sub text-danger">{{ trans_choice('sadmin_billing.overdue_by', $late, ['count' => $late]) }}</span>@endif
                            </td>
                            <td class="actions">
                                @if($canManage && in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true))
                                    <button class="button button--secondary button--sm" type="button" wire:click="openPayment({{ $invoice->id }})">{{ __('sadmin_billing.actions.record') }}</button>
                                @endif
                                <button class="button button--ghost button--sm" type="button" wire:click="showInvoice({{ $invoice->id }})">{{ __('sadmin_billing.actions.view') }}</button>
                                <a class="icon-button" href="{{ route('superadmin.billing.invoice.print', ['invoice' => $invoice->uuid, 'print' => 1]) }}" target="_blank" rel="noopener" title="{{ __('sadmin_billing.actions.print') }}" aria-label="{{ __('sadmin_billing.actions.print') }}: {{ $invoice->number }}"><x-ui.icon name="print" size="16" /></a>
                                <a class="icon-button" href="{{ route('superadmin.billing.invoice.pdf', ['invoice' => $invoice->uuid]) }}" title="{{ __('sadmin_billing.actions.pdf') }}" aria-label="{{ __('sadmin_billing.actions.pdf') }}: {{ $invoice->number }}"><x-ui.icon name="download" size="16" /></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $invoices->links() }}

    {{-- ── Invoice detail ─────────────────────────────────────────────── --}}
    @if($selected && str_starts_with((string) $panel, 'invoice:'))
        @php
            $state = $effective($selected);
            $balance = $selected->balance();
            $netPaid = $selected->payments->whereNull('reversed_at')->sum('amount_minor');
        @endphp
        <x-ui.drawer :title="$selected->number" :description="$selected->tenant?->name" size="lg">
            <div class="stack">
                <div class="cluster">
                    <x-ui.status :value="$state" :label="Label::for('invoice_status', $state)" />
                    @if($selected->billing_period)<span class="chip">{{ __('sadmin_billing.cycle_label.'.$selected->billing_period) }}</span>@endif
                    <span class="chip" dir="ltr">{{ $selected->currency }}</span>
                </div>
                <div class="cluster">
                    <a class="button button--secondary button--sm" href="{{ route('superadmin.billing.invoice.print', ['invoice' => $selected->uuid]) }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('sadmin_billing.actions.print') }}</a>
                    <a class="button button--secondary button--sm" href="{{ route('superadmin.billing.invoice.pdf', ['invoice' => $selected->uuid]) }}"><x-ui.icon name="download" size="16" />{{ __('sadmin_billing.actions.pdf') }}</a>
                    @if($selected->tenant)
                        <a class="button button--ghost button--sm" href="{{ route('superadmin.centers.show', ['tenant' => $selected->tenant_id, 'tab' => 'billing']) }}#statement" wire:navigate><x-ui.icon name="file-text" size="16" />{{ __('sadmin_billing.actions.statement') }}</a>
                    @endif
                </div>
                <dl class="kv-grid">
                    <div><dt>{{ __('sadmin_billing.detail.center') }}</dt><dd>@if($selected->tenant)<a class="cell-link" href="{{ route('superadmin.centers.show', ['tenant' => $selected->tenant_id, 'tab' => 'billing']) }}" wire:navigate>{{ $selected->tenant->name }}</a>@else — @endif</dd></div>
                    <div><dt>{{ __('sadmin_billing.detail.plan') }}</dt><dd>{{ $planName($selected) ?? '—' }}</dd></div>
                    <div><dt>{{ __('sadmin_billing.detail.issued') }}</dt><dd>{{ $date($selected->issued_at) }}</dd></div>
                    <div><dt>{{ __('sadmin_billing.detail.due') }}</dt><dd>{{ $date($selected->due_at) }}</dd></div>
                    @if($selected->period_start)
                        <div><dt>{{ __('sadmin_billing.detail.period') }}</dt><dd>{{ $date($selected->period_start) }} – {{ $date($selected->period_end) }}</dd></div>
                    @endif
                    @if($selected->reference)<div><dt>{{ __('sadmin_billing.fields.reference') }}</dt><dd dir="ltr">{{ $selected->reference }}</dd></div>@endif
                    @if($selected->settled_at)
                        <div><dt>{{ __('sadmin_billing.detail.settled') }}</dt><dd>{{ $date($selected->settled_at) }}</dd></div>
                    @endif
                </dl>

                @if($selected->status === 'void')
                    <div class="notice" data-tone="warning"><x-ui.icon name="alert-triangle" /><p>{{ __('sadmin_billing.detail.voided', ['date' => $date($selected->voided_at), 'name' => $selected->voided_by_label]) }}@if($selected->void_reason) — {{ $selected->void_reason }}@endif</p></div>
                @endif

                <section class="drawer-section">
                    <h3>{{ __('sadmin_billing.detail.items') }}</h3>
                    <ul class="line-items">
                        @foreach($selected->items as $item)
                            <li><span>{{ $item->description }} @if($item->quantity > 1)<span class="muted">× {{ $item->quantity }}</span>@endif</span><span dir="ltr" class="tabular">{{ $fmt((int) $item->total_minor, $selected->currency) }}</span></li>
                        @endforeach
                    </ul>
                    <dl class="summary-list">
                        <div><dt>{{ __('sadmin_billing.detail.subtotal') }}</dt><dd dir="ltr">{{ $fmt($selected->subtotal_minor, $selected->currency) }}</dd></div>
                        @if($selected->discount_minor > 0)<div><dt>{{ __('sadmin_billing.fields.discount') }}</dt><dd dir="ltr">−{{ $fmt($selected->discount_minor, $selected->currency) }}</dd></div>@endif
                        <div class="settlement__grand"><dt>{{ __('sadmin_billing.detail.total') }}</dt><dd dir="ltr">{{ $fmt($selected->total_minor, $selected->currency) }}</dd></div>
                        <div><dt>{{ __('sadmin_billing.detail.paid') }}</dt><dd dir="ltr">{{ $fmt((int) $netPaid, $selected->currency) }}</dd></div>
                        <div><dt>{{ __('sadmin_billing.detail.balance') }}</dt><dd dir="ltr" @class(['text-danger' => $state === 'overdue'])>{{ $fmt($balance, $selected->currency) }}</dd></div>
                    </dl>
                </section>

                <section class="drawer-section">
                    <h3>{{ __('sadmin_billing.detail.payments') }}</h3>
                    @if($selected->payments->isEmpty())
                        <p class="muted">{{ __('sadmin_billing.detail.no_payments') }}</p>
                    @else
                        <ol class="timeline">
                            @foreach($selected->payments->sortByDesc('received_at') as $payment)
                                <li class="timeline__item" wire:key="payment-{{ $payment->id }}">
                                    <span class="timeline__dot" data-tone="{{ $payment->reversed_at ? 'danger' : 'success' }}" aria-hidden="true"><x-ui.icon :name="$payment->reversed_at ? 'undo' : 'check'" /></span>
                                    <div class="timeline__body">
                                        <div class="cluster cluster--between">
                                            <strong dir="ltr" @class(['struck' => $payment->reversed_at])>{{ $fmt((int) $payment->amount_minor, $payment->currency) }}</strong>
                                            @if($payment->reversed_at)
                                                <x-ui.status value="reversed" tone="danger" :label="__('sadmin_billing.detail.reversed')" :dot="false" />
                                            @elseif($canManage && $selected->status !== 'void')
                                                <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="openCorrection('reverse:{{ $payment->id }}')"><x-ui.icon name="undo" size="14" />{{ __('sadmin_billing.actions.reverse') }}</button>
                                            @endif
                                        </div>
                                        <p>{{ Label::for('payment_method', $payment->method) }}@if($payment->reference) · <span dir="ltr">{{ $payment->reference }}</span>@endif</p>
                                        @if($payment->note)<p class="muted">{{ $payment->note }}</p>@endif
                                        <div class="timeline__meta">{{ $date($payment->received_at) }} · {{ __('sadmin_billing.detail.recorded_by', ['name' => $payment->recorded_by_label]) }}</div>
                                        @if($payment->reversed_at)
                                            <div class="timeline__meta text-danger">{{ __('sadmin_billing.detail.reversed_by', ['name' => $payment->reversed_by_label, 'date' => $date($payment->reversed_at)]) }}@if($payment->reversal_reason) — {{ $payment->reversal_reason }}@endif</div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                @if($selected->notes)
                    <section class="drawer-section">
                        <h3>{{ __('sadmin_billing.detail.notes') }}</h3>
                        <p class="prewrap">{{ $selected->notes }}</p>
                    </section>
                @endif
            </div>
            @if($canManage && $selected->status !== 'void')
                <x-slot:footer>
                    @if($selected->paid_minor === 0)
                        <button class="button button--ghost button--danger-text" type="button" wire:click="openCorrection('void:{{ $selected->id }}')"><x-ui.icon name="x-circle" size="16" />{{ __('sadmin_billing.actions.void') }}</button>
                    @endif
                    @if(in_array($selected->status, ['issued', 'partially_paid', 'overdue'], true))
                        <button class="button" type="button" wire:click="openPayment({{ $selected->id }})"><x-ui.icon name="wallet" size="16" />{{ __('sadmin_billing.actions.record') }}</button>
                    @endif
                </x-slot:footer>
            @endif
        </x-ui.drawer>
    @endif

    {{-- ── Record payment ─────────────────────────────────────────────── --}}
    @if($selected && str_starts_with((string) $panel, 'pay:'))
        <x-ui.modal :title="__('sadmin_billing.payment_form.title', ['number' => $selected->number])" :description="$selected->tenant?->name" icon="wallet" submit="recordPayment">
            <dl class="summary-list">
                <div><dt>{{ __('sadmin_billing.payment_form.balance') }}</dt><dd dir="ltr">{{ $fmt($selected->balance(), $selected->currency) }}</dd></div>
            </dl>
            <x-ui.field :label="__('sadmin_billing.payment_form.amount')" for="payment-amount" name="paymentAmount" :help="__('sadmin_billing.payment_form.amount_help')" required>
                <div class="input-group">
                    <input id="payment-amount" dir="ltr" inputmode="decimal" autocomplete="off" wire:model="paymentAmount" required>
                    <span class="input-group__addon">{{ $selected->currency }}</span>
                </div>
            </x-ui.field>
            <div class="form-grid">
                <x-ui.field :label="__('sadmin_billing.payment_form.method')" for="payment-method" name="paymentMethod" required>
                    <select id="payment-method" wire:model="paymentMethod">
                        @foreach(\App\Modules\SaasBilling\Application\Actions\RecordManualSaasPayment::METHODS as $method)
                            <option value="{{ $method }}">{{ Label::for('payment_method', $method) }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_billing.payment_form.received_at')" for="payment-received" name="receivedAt" required>
                    <input id="payment-received" type="datetime-local" wire:model="receivedAt" max="{{ now()->format('Y-m-d\TH:i') }}" required>
                </x-ui.field>
            </div>
            <x-ui.field :label="__('sadmin_billing.payment_form.reference')" for="payment-reference" name="reference">
                <input id="payment-reference" dir="ltr" wire:model="reference" autocomplete="off">
            </x-ui.field>
            <x-ui.field :label="__('sadmin_billing.fields.note')" for="payment-note" name="paymentNote">
                <textarea id="payment-note" rows="2" wire:model="paymentNote" maxlength="1000"></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="recordPayment">{{ __('sadmin_billing.payment_form.submit') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Corrections ────────────────────────────────────────────────── --}}
    @if(($selected && str_starts_with((string) $panel, 'void:')) || $reversePayment)
        @php $voiding = str_starts_with((string) $panel, 'void:'); @endphp
        <x-ui.modal :title="$voiding ? __('sadmin_billing.void_form.title', ['number' => $selected?->number]) : __('sadmin_billing.reverse_form.title')"
            :description="$voiding ? __('sadmin_billing.void_form.body') : __('sadmin_billing.reverse_form.body', ['amount' => $fmt($reversePayment?->amount_minor ?? 0, $reversePayment?->currency)])"
            :icon="$voiding ? 'x-circle' : 'undo'" tone="danger" submit="correct">
            <x-ui.field :label="__('sadmin_billing.fields.reason')" for="correction-reason" name="correctionReason" required>
                <textarea id="correction-reason" rows="2" wire:model="correctionReason" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="correct">{{ $voiding ? __('sadmin_billing.actions.void') : __('sadmin_billing.actions.reverse') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Issue invoice ──────────────────────────────────────────────── --}}
    @if($panel === 'issue')
        <x-ui.drawer :title="__('sadmin_billing.issue_form.title')" submit="issue" size="lg">
            <x-ui.field :label="__('sadmin_billing.issue_form.subscription')" for="issue-subscription" name="subscriptionId" required>
                <select id="issue-subscription" wire:model.live="subscriptionId" required>
                    <option value="">{{ __('sadmin_billing.issue_form.choose_subscription') }}</option>
                    @foreach($subscriptions as $subscription)
                        <option value="{{ $subscription->id }}">{{ $subscription->tenant?->name }} · {{ $subscription->plan?->name?->get() }} · {{ Label::for('billing_period', $subscription->billing_period_snapshot) }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_billing.issue_form.description')" for="issue-description" name="description" required>
                <input id="issue-description" wire:model="description" maxlength="190" required>
            </x-ui.field>
            <div class="form-grid">
                <x-ui.field :label="__('sadmin_billing.fields.cycle')" for="issue-cycle" name="billingCycle">
                    <select id="issue-cycle" wire:model.live="billingCycle">
                        <option value="">—</option>
                        @foreach(['monthly', 'yearly'] as $value)<option value="{{ $value }}">{{ __('sadmin_billing.cycle_label.'.$value) }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_billing.fields.period_start')" for="issue-start" name="periodStart">
                    <input id="issue-start" type="date" wire:model="periodStart">
                </x-ui.field>
                <x-ui.field :label="__('sadmin_billing.fields.period_end')" for="issue-end" name="periodEnd">
                    <input id="issue-end" type="date" wire:model="periodEnd">
                </x-ui.field>
            </div>
            <div class="form-grid">
                <x-ui.field :label="__('sadmin_billing.issue_form.amount')" for="issue-amount" name="amount" required>
                    <div class="input-group">
                        <input id="issue-amount" dir="ltr" inputmode="decimal" autocomplete="off" wire:model="amount" required>
                        <span class="input-group__addon">{{ $issueCurrency ?? '—' }}</span>
                    </div>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_billing.fields.discount')" for="issue-discount" name="discount">
                    <div class="input-group">
                        <input id="issue-discount" dir="ltr" inputmode="decimal" autocomplete="off" wire:model="discount">
                        <span class="input-group__addon">{{ $issueCurrency ?? '—' }}</span>
                    </div>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_billing.issue_form.due_at')" for="issue-due" name="dueAt" required>
                    <input id="issue-due" type="datetime-local" wire:model="dueAt" min="{{ now()->format('Y-m-d\TH:i') }}" required>
                </x-ui.field>
            </div>
            <x-ui.field :label="__('sadmin_billing.fields.reference')" for="issue-reference" name="invoiceReference">
                <input id="issue-reference" dir="ltr" wire:model="invoiceReference" maxlength="190" autocomplete="off">
            </x-ui.field>
            <x-ui.field :label="__('sadmin_billing.issue_form.notes')" for="issue-notes" name="notes">
                <textarea id="issue-notes" rows="3" wire:model="notes" maxlength="2000"></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="issue">{{ __('sadmin_billing.issue_form.submit') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
