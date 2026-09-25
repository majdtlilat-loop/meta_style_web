<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Billing;

use App\Kernel\Audit\Actor;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\SaasBilling\Application\Actions\CorrectSaasBilling;
use App\Modules\SaasBilling\Application\Actions\IssueSaasInvoice;
use App\Modules\SaasBilling\Application\Actions\RecordManualSaasPayment;
use App\Modules\SaasBilling\Application\SaasBillingTotals;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\Models\SaasPayment;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Meta Style's own invoices to centers — never a center's Finance.
 *
 * Amounts are typed in major units of the invoice's currency and parsed
 * through the platform currency catalog. The Actions stay the only writers:
 * issue, settle (full or partial), reverse a settlement, void an unpaid
 * invoice. Nothing here deletes a financial row. "Overdue" is read from the
 * due date, so an invoice shows late the moment it is.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    public const FILTERS = ['open', 'overdue', 'partially_paid', 'settled', 'void'];

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $center = '';

    #[Url(except: '')]
    public string $cycle = '';

    /** `issue`, `invoice:<id>`, `pay:<id>`, `void:<id>`, `reverse:<paymentId>`. */
    public ?string $panel = null;

    public ?int $subscriptionId = null;

    public string $description = '';

    public string $amount = '';

    public string $discount = '';

    public string $billingCycle = '';

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $dueAt = '';

    public string $notes = '';

    public string $invoiceReference = '';

    public ?int $invoiceId = null;

    public string $paymentAmount = '';

    public string $paymentMethod = 'bank_transfer';

    public string $receivedAt = '';

    public string $reference = '';

    public string $paymentNote = '';

    public string $correctionReason = '';

    public function mount(): void
    {
        $uuid = request()->query('invoice');
        if (is_string($uuid) && $uuid !== '') {
            $invoice = SaasInvoice::query()->where('uuid', $uuid)->first();
            if ($invoice instanceof SaasInvoice) {
                $this->panel = 'invoice:'.$invoice->id;
            }
        }
        if (request()->query('panel') === 'issue' && auth('platform')->user()?->hasPermission('platform.billing.manage')) {
            $this->openIssue();
            if ($this->center !== '') {
                $this->subscriptionId = Subscription::query()->where('tenant_id', $this->center)->value('id');
                $this->updatedSubscriptionId();
            }
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'center', 'cycle'], true)) {
            $this->resetPage();
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, self::FILTERS, true) ? $status : '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'search', 'center', 'cycle');
        $this->resetPage();
    }

    public function openIssue(): void
    {
        $this->requirePlatformPermission('platform.billing.manage');
        $this->closePanel();
        $this->dueAt = now()->addDays(14)->format('Y-m-d\TH:i');
        $this->panel = 'issue';
    }

    public function updatedSubscriptionId(): void
    {
        $subscription = $this->subscriptionId === null ? null : Subscription::query()->with('plan')->find($this->subscriptionId);
        if (! $subscription instanceof Subscription) {
            return;
        }

        $currency = $this->currencyOf($subscription);
        if ($this->amount === '' && $subscription->price_minor_snapshot > 0) {
            $this->amount = app(PlatformCurrencies::class)->major((int) $subscription->price_minor_snapshot, $currency);
        }
        if ($this->description === '') {
            $this->description = __('sadmin_billing.default_description', ['plan' => $subscription->plan?->name?->get() ?? '']);
        }
        $this->billingCycle = (string) $subscription->billing_period_snapshot;
        $this->periodStart = $subscription->current_period_start?->format('Y-m-d') ?? '';
        $this->periodEnd = $subscription->current_period_end?->format('Y-m-d') ?? '';
    }

    /**
     * Switching the cycle offers that cycle's price and a matching period, so
     * a yearly invoice is not issued at the monthly amount by mistake. Both
     * stay editable.
     */
    public function updatedBillingCycle(): void
    {
        $subscription = $this->subscriptionId === null ? null : Subscription::query()->with('plan')->find($this->subscriptionId);
        $plan = $subscription?->plan;
        if (! $plan instanceof Plan || ! in_array($this->billingCycle, Plan::CYCLES, true)) {
            return;
        }

        $currency = $this->currencyOf($subscription);
        $price = $plan->priceFor($this->billingCycle);
        if ($price !== null && mb_strtoupper($plan->currency) === $currency) {
            $this->amount = app(PlatformCurrencies::class)->major($price, $currency);
        }
        if ($this->periodStart !== '') {
            $start = Carbon::parse($this->periodStart);
            $this->periodEnd = ($this->billingCycle === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow())->format('Y-m-d');
        }
    }

    public function showInvoice(int $invoiceId): void
    {
        $this->closePanel();
        $this->panel = 'invoice:'.SaasInvoice::query()->findOrFail($invoiceId)->id;
    }

    public function openPayment(int $invoiceId): void
    {
        $this->requirePlatformPermission('platform.billing.manage');
        $invoice = SaasInvoice::query()->findOrFail($invoiceId);
        $this->closePanel();
        $this->invoiceId = $invoice->id;
        $balance = $invoice->balance();
        $this->paymentAmount = $balance > 0 ? app(PlatformCurrencies::class)->major($balance, $invoice->currency) : '';
        $this->receivedAt = now()->format('Y-m-d\TH:i');
        $this->panel = 'pay:'.$invoice->id;
    }

    public function openCorrection(string $panel): void
    {
        $this->requirePlatformPermission('platform.billing.manage');
        $back = $this->panel;
        $this->closePanel();
        if (preg_match('/^(void|reverse):\d+$/', $panel) === 1) {
            $this->panel = $panel;
        } else {
            $this->panel = $back;
        }
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('subscriptionId', 'description', 'amount', 'discount', 'billingCycle', 'periodStart', 'periodEnd', 'dueAt', 'notes', 'invoiceReference',
            'invoiceId', 'paymentAmount', 'paymentMethod', 'receivedAt', 'reference', 'paymentNote', 'correctionReason');
        $this->resetValidation();
    }

    public function issue(IssueSaasInvoice $issue): void
    {
        $user = $this->requirePlatformPermission('platform.billing.manage');
        $data = $this->validate([
            'subscriptionId' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:190'],
            'amount' => ['required', 'string', 'max:32'],
            'discount' => ['nullable', 'string', 'max:32'],
            'billingCycle' => ['nullable', 'in:monthly,yearly,quarterly'],
            'periodStart' => ['nullable', 'date'],
            'periodEnd' => ['nullable', 'date', 'after_or_equal:periodStart'],
            'dueAt' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'invoiceReference' => ['nullable', 'string', 'max:190'],
        ], [], $this->attributeNames());
        $subscription = Subscription::query()->with('plan')->findOrFail($data['subscriptionId']);
        $currency = $this->currencyOf($subscription);
        $minor = $this->parse($data['amount'], $currency, 'amount');
        $discount = trim((string) $data['discount']) === '' ? 0 : $this->parse((string) $data['discount'], $currency, 'discount');
        if ($minor === null || $discount === null) {
            return;
        }
        if ($minor <= 0) {
            $this->addError('amount', __('ui.errors.amount'));

            return;
        }

        try {
            $issue(
                $subscription, $data['description'], $minor, Carbon::parse($data['dueAt']), Actor::platform($user), $data['notes'] ?: null,
                $discount, $data['invoiceReference'] ?: null, $data['billingCycle'] ?: null,
                $data['periodStart'] ? Carbon::parse($data['periodStart']) : null,
                $data['periodEnd'] ? Carbon::parse($data['periodEnd']) : null,
            );
        } catch (DomainException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', __('sadmin_billing.invoice_issued'));
    }

    public function recordPayment(RecordManualSaasPayment $record): void
    {
        $user = $this->requirePlatformPermission('platform.billing.manage');
        $data = $this->validate([
            'invoiceId' => ['required', 'integer'],
            'paymentAmount' => ['required', 'string', 'max:32'],
            'paymentMethod' => ['required', 'in:'.implode(',', RecordManualSaasPayment::METHODS)],
            'receivedAt' => ['required', 'date', 'before_or_equal:now'],
            'reference' => ['nullable', 'string', 'max:190'],
            'paymentNote' => ['nullable', 'string', 'max:1000'],
        ], [], $this->attributeNames());
        $invoice = SaasInvoice::query()->findOrFail($data['invoiceId']);
        $minor = $this->parse($data['paymentAmount'], $invoice->currency, 'paymentAmount');
        if ($minor === null) {
            return;
        }

        try {
            $record($invoice, $minor, $data['paymentMethod'], Carbon::parse($data['receivedAt']), Actor::platform($user), $data['reference'] ?: null, $data['paymentNote'] ?: null);
        } catch (DomainException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', __('sadmin_billing.payment_recorded'));
    }

    public function correct(CorrectSaasBilling $correct): void
    {
        $user = $this->requirePlatformPermission('platform.billing.manage');
        $this->validate(['correctionReason' => ['required', 'string', 'min:5', 'max:1000']], [], $this->attributeNames());
        [$kind, $id] = array_pad(explode(':', (string) $this->panel, 2), 2, '');

        try {
            $invoiceId = match ($kind) {
                'void' => $correct->voidInvoice(SaasInvoice::query()->findOrFail((int) $id), Actor::platform($user), $this->correctionReason)->id,
                'reverse' => $correct->reversePayment(SaasPayment::query()->findOrFail((int) $id), Actor::platform($user), $this->correctionReason)->invoice_id,
                default => abort(404),
            };
        } catch (DomainException $e) {
            $this->addError('correctionReason', $e->getMessage());

            return;
        }

        $this->closePanel();
        $this->panel = 'invoice:'.$invoiceId;
        session()->flash('notice', __('sadmin_billing.corrected.'.$kind));
    }

    public function render(PlatformCurrencies $currencies, SaasBillingTotals $totals): mixed
    {
        $user = $this->requirePlatformPermission('platform.billing.manage');
        $now = Carbon::now();

        $invoices = $this->filtered()
            ->with('tenant:id,name,slug')
            ->latest('issued_at')
            ->orderByDesc('id')
            ->paginate(20);

        $open = SaasInvoice::query()->whereIn('status', SaasBillingTotals::OPEN);
        $reversePayment = $this->panel !== null && str_starts_with($this->panel, 'reverse:') ? SaasPayment::query()->find((int) substr($this->panel, 8)) : null;

        return view('livewire.sadmin.billing.index', [
            'invoices' => $invoices,
            'now' => $now,
            'summary' => [
                'outstanding' => collect($totals->outstanding()),
                'overdue' => (clone $open)->where('due_at', '<', $now)->count(),
                'collected' => collect($totals->collectedSince($now->copy()->startOfMonth())),
            ],
            'centerName' => $this->center === '' ? null : TenantModel::query()->whereKey($this->center)->value('name'),
            'selected' => $this->selectedInvoice(),
            'reversePayment' => $reversePayment,
            'subscriptions' => $this->panel === 'issue'
                ? Subscription::query()->with(['tenant:id,name', 'plan'])->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])->get()->sortBy(fn (Subscription $s) => $s->tenant?->name)
                : collect(),
            'issueCurrency' => $this->panel === 'issue' && $this->subscriptionId !== null
                ? $this->currencyOf(Subscription::query()->with('plan')->find($this->subscriptionId))
                : null,
            'money' => $currencies,
            'canManage' => $user->hasPermission('platform.billing.manage'),
            'hasFilters' => $this->status !== '' || $this->search !== '' || $this->center !== '' || $this->cycle !== '',
        ]);
    }

    /**
     * @return Builder<SaasInvoice>
     */
    private function filtered(): Builder
    {
        $now = Carbon::now();

        return SaasInvoice::query()
            ->when($this->center !== '', fn (Builder $q) => $q->where('tenant_id', $this->center))
            ->when(in_array($this->cycle, ['monthly', 'yearly'], true), fn (Builder $q) => $q->where('billing_period', $this->cycle))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhere('reference', 'like', '%'.$this->search.'%')
                ->orWhereHas('tenant', fn (Builder $tenant) => $tenant->where('name', 'like', '%'.$this->search.'%'))))
            ->when($this->status === 'open', fn (Builder $q) => $q->whereIn('status', ['issued', 'partially_paid', 'overdue']))
            ->when($this->status === 'overdue', fn (Builder $q) => $q->whereIn('status', ['issued', 'partially_paid', 'overdue'])->where('due_at', '<', $now))
            ->when(in_array($this->status, ['partially_paid', 'settled', 'void'], true), fn (Builder $q) => $q->where('status', $this->status));
    }

    private function selectedInvoice(): ?SaasInvoice
    {
        if ($this->panel === null || ! preg_match('/^(invoice|pay|void):(\d+)$/', $this->panel, $match)) {
            return null;
        }

        /** @var SaasInvoice|null $invoice */
        $invoice = SaasInvoice::query()->with(['tenant:id,name,slug', 'items', 'payments'])->find((int) $match[2]);

        return $invoice;
    }

    private function currencyOf(?Subscription $subscription): string
    {
        return mb_strtoupper((string) ($subscription?->currency_snapshot ?: $subscription?->plan?->currency ?: app(PlatformCurrencies::class)->defaultCode()));
    }

    private function parse(string $typed, string $currency, string $field): ?int
    {
        try {
            return app(PlatformCurrencies::class)->parse($typed, $currency);
        } catch (InvalidArgumentException $exception) {
            $this->addError($field, $exception->getMessage());

            return null;
        }
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return [
            'subscriptionId' => __('sadmin_billing.fields.center'),
            'description' => __('sadmin_billing.fields.description'),
            'amount' => __('sadmin_billing.fields.amount'),
            'discount' => __('sadmin_billing.fields.discount'),
            'periodStart' => __('sadmin_billing.fields.period_start'),
            'periodEnd' => __('sadmin_billing.fields.period_end'),
            'dueAt' => __('sadmin_billing.fields.due'),
            'paymentAmount' => __('sadmin_billing.fields.amount'),
            'receivedAt' => __('sadmin_billing.fields.received'),
            'correctionReason' => __('sadmin_billing.fields.reason'),
        ];
    }
}
