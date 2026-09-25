<?php

declare(strict_types=1);

use App\Kernel\Localization\TranslatedText;
use App\Livewire\Center\Benefits\PackageHolders;
use App\Livewire\Center\CustomerBenefitsPanel;
use App\Livewire\Center\PackageDefinitions;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager packages page
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§13–16, 22, 27. Items can name a
| variation; a definition is edited from its own figures, archived and put
| back on sale; holders are listed with the sessions left.
|
*/

it('sells a package of one variation and edits it from its stored items', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        /** @var ServiceVariation $long */
        $long = ServiceVariation::query()->create([
            'service_id' => $seed['service']->getKey(),
            'name' => TranslatedText::make('en', 'Long hair'),
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $page = Livewire::actingAs($owner)->test(PackageDefinitions::class)
            ->call('openForm')
            // The seeded service already has variations of its own; the new one is offered too.
            ->assertViewHas('variations', fn (array $variations): bool => in_array($long->uuid, array_column($variations[$seed['service']->uuid] ?? [], 'uuid'), true))
            ->set('name', ['en' => 'Four long cuts'])
            ->set('price', '70000')
            ->set('validityDays', '120')
            ->set('items', [['service' => $seed['service']->uuid, 'variation' => $long->uuid, 'quantity' => '4']])
            ->call('save')
            ->assertSet('error', '')
            ->assertSee('Four long cuts')
            ->assertSee('Long hair');

        $definition = PackageDefinition::query()->sole();

        $page->call('edit', $definition->uuid)
            ->assertSet('price', '70000')
            ->assertSet('validityDays', '120')
            ->assertSet('items', [['service' => $seed['service']->uuid, 'variation' => $long->uuid, 'quantity' => '4']])
            // Choosing another service clears the variation chosen for the old one.
            ->set('items.0.service', '')
            ->set('items.0.service', $seed['service']->uuid)
            ->assertSet('items.0.variation', '')
            ->set('items.0.quantity', '6')
            ->call('save')
            ->assertSet('error', '');

        expect($definition->refresh()->items()->sole()->quantity)->toBe(6)
            ->and($definition->items()->sole()->service_variation_id)->toBeNull()
            ->and(PackageDefinition::query()->count())->toBe(1);
    });
});

it('archives and restores a package, and lists holders with sessions left', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Holder Huda', '0750 654 0000');

        $package = $this->paidPackage($seed, $owner, $customer);
        $definition = PackageDefinition::query()->sole();

        expect(app(PackagesQuery::class)->liveCountsByDefinition($owner))->toBe([$definition->uuid => 1]);

        Livewire::actingAs($owner)->test(PackageHolders::class)
            ->assertSee('Holder Huda')
            ->assertSee('5 of 5 left');

        Livewire::actingAs($owner)->test(PackageDefinitions::class)
            ->assertSee('1 active holder')
            ->call('archive', $definition->uuid)
            ->assertSet('error', '')
            ->call('setView', 'archived')
            ->assertSee('5 haircuts')
            ->call('restore', $definition->uuid)
            ->assertSet('error', '');

        expect($definition->refresh()->archived_at)->toBeNull();

        // The customer's own package is untouched by any of it, and can be
        // cancelled from their page with a reason.
        Livewire::actingAs($owner)
            ->test(CustomerBenefitsPanel::class, ['customer' => $customer->uuid, 'section' => 'plans'])
            ->assertSee('5 of 5 left')
            ->call('startCancel', 'package:'.$package->uuid)
            ->set('cancelReason', 'x')
            ->call('cancel')
            ->assertSet('error', 'Cancelling a package needs a reason.')
            ->set('cancelReason', 'Moved away')
            ->call('cancel')
            ->assertSet('error', '')
            ->assertSee('Moved away');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
