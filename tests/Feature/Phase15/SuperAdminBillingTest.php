<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\SaaS\Models\Subscription;
use App\Livewire\Sadmin\Billing\Index as BillingIndex;
use App\Livewire\Sadmin\Plans\Index as PlansIndex;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Amounts are typed in major units
|--------------------------------------------------------------------------
|
| Nobody types "5000000" to mean 50 000 IQD, or "1999" to mean $19.99. The
| screens take what a person would write — including Arabic-Indic digits —
| and Kernel\Money turns it into minor units, refusing what it cannot parse.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->actingAs(PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail(), 'platform');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('issues an invoice and records partial then full payment from major-unit input', function (): void {
    $center = $this->registerCenter('Billing Center', 'owner@billing.test');
    $subscription = Subscription::query()->where('tenant_id', $center['tenant']->id)->firstOrFail();
    $subscription->forceFill(['currency_snapshot' => 'IQD'])->save();

    Livewire::test(BillingIndex::class)
        ->call('openIssue')
        ->assertSet('panel', 'issue')
        ->set('subscriptionId', $subscription->id)
        ->set('description', 'Subscription — September')
        ->set('amount', '٥٠٬٠٠٠')
        ->set('dueAt', now()->addDays(10)->format('Y-m-d\TH:i'))
        ->call('issue')
        ->assertHasNoErrors()
        ->assertSet('panel', null);

    $invoice = SaasInvoice::query()->where('tenant_id', $center['tenant']->id)->latest('id')->firstOrFail();
    expect($invoice->total_minor)->toBe(50000)
        ->and($invoice->currency)->toBe('IQD');

    Livewire::test(BillingIndex::class)
        ->call('openPayment', $invoice->id)
        ->assertSet('paymentAmount', '50000')
        ->set('paymentAmount', '20000.5')
        ->call('recordPayment')
        ->assertHasErrors('paymentAmount')
        ->set('paymentAmount', '60000')
        ->call('recordPayment')
        ->assertHasErrors('paymentAmount')
        ->set('paymentAmount', '20,000')
        ->set('paymentMethod', 'bank_transfer')
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($invoice->refresh()->paid_minor)->toBe(20000)
        ->and($invoice->status)->toBe('partially_paid');

    Livewire::test(BillingIndex::class)
        ->call('openPayment', $invoice->id)
        ->assertSet('paymentAmount', '30000')
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($invoice->refresh()->status)->toBe('settled')
        ->and($invoice->paid_minor)->toBe(50000);

    Livewire::test(BillingIndex::class)
        ->set('center', $center['tenant']->id)
        ->assertSee($invoice->number)
        ->call('showInvoice', $invoice->id)
        ->assertSee('Subscription — September');
});

it('parses a plan price typed in major units for the chosen currency', function (): void {
    Livewire::test(PlansIndex::class)
        ->call('create')
        ->set('currency', 'USD')
        ->set('price', '19.99')
        ->assertSet('priceMinor', 1999)
        ->set('currency', 'IQD')
        ->assertHasErrors('price')
        ->set('price', '٢٥٠٠٠')
        ->assertHasNoErrors('price')
        ->assertSet('priceMinor', 25000);
});
