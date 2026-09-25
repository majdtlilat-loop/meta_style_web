<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Customers\Concerns\FormatsLocalDates;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Sales\Application\CustomerProfileSales;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The customer page's purchases: issued sales and their invoice numbers.
 *
 * Sales' own read (`sale.view` + branch scope, never `pos`: what was charged
 * stays readable after a downgrade) and presenter. Every amount is the total
 * `SalePricing` already wrote — nothing here adds anything up, and nothing is
 * called revenue (docs/18 §55, docs/20).
 */
final class PurchasesPanel extends Component
{
    use FormatsLocalDates;

    #[Locked]
    public string $customer = '';

    public function render(CustomerQuery $customers, CustomerProfileSales $sales, SalesPresenter $presenter, Entitlements $entitlements): View
    {
        $user = $this->user();

        try {
            $customer = $customers->find($this->customer, $user);
            $found = $sales->forCustomer($user, (int) $customer->getKey());
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        $canPrint = $entitlements->enabled('printing');

        return view('livewire.center.customers.purchases-panel', [
            'sales' => array_map(fn (Sale $sale): array => $this->row($presenter->sale($sale, $user, false), $sale, $canPrint), $found),
            'salesUrl' => route('center.sales'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private function row(array $shape, Sale $sale, bool $canPrint): array
    {
        /** @var array<string, mixed>|null $invoice */
        $invoice = $shape['invoice'];
        $timezone = $sale->relationLoaded('branch') ? $sale->branch?->timezone : null;
        $voided = $shape['status'] === 'voided';

        return [
            'uuid' => $shape['uuid'],
            'number' => $invoice['number'] ?? null,
            'date' => $this->localDateTime($sale->finalized_at, $timezone),
            'branch' => $sale->relationLoaded('branch') ? $sale->branch?->name->get() : null,
            'total' => $shape['grand_total']['formatted'] ?? null,
            'discount' => ($shape['discount_total']['amount'] ?? 0) > 0 ? $shape['discount_total']['formatted'] : null,
            'voided' => $voided,
            'void_reason' => $shape['void_reason'],
            'print_url' => $canPrint && $invoice !== null ? route('center.sales.invoice.print', ['uuid' => $invoice['uuid'], 'format' => 'a4']) : null,
        ];
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
