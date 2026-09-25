<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Livewire\Center\PosSettings;
use App\Modules\Catalog\Domain\Models\Product;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| POS settings
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§6, 17. Products named once per ENABLED content language
| (a switched-off language keeps its text), priced by parsing what was typed;
| each branch's invoice prefix with the Action's own refusals.
|
*/

it('edits a product per content language, takes it off sale and archives it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');

        $product = $this->seedProduct('Beard Oil', 15000, '6291041500213');
        $product->forceFill(['name' => TranslatedText::fromArray(['en' => 'Beard Oil', 'ar' => 'زيت اللحية', 'ckb' => 'ڕۆنی ڕیش'])])->save();

        $this->actingAs($owner, 'web');

        $screen = Livewire::test(PosSettings::class)
            ->assertSee('Beard Oil')
            ->call('editProduct', $product->uuid)
            ->assertSet('productPrice', '15000')
            ->set('productNames.en', 'Beard Oil 50ml')
            ->set('productPrice', '17500')
            ->set('productActive', false)
            ->call('saveProduct')
            ->assertSet('error', '')
            ->assertSet('editingProduct', false);

        $fresh = Product::query()->sole();

        expect($fresh->name->all())->toBe(['en' => 'Beard Oil 50ml', 'ar' => 'زيت اللحية', 'ckb' => 'ڕۆنی ڕیش'])
            ->and($fresh->price_minor)->toBe(17500)
            ->and($fresh->is_active)->toBeFalse();

        // A price is parsed, never guessed; a code is checked by the Action.
        $screen->call('newProduct')
            ->set('productNames', ['en' => 'Comb'])
            ->set('productPrice', 'twelve')
            ->call('saveProduct')
            ->assertSet('error', 'Enter a price like 12000.')
            ->set('productPrice', '3000')
            ->set('productBarcode', 'bad code!')
            ->call('saveProduct')
            ->assertSet('error', 'Use letters, digits, dashes, dots or underscores only.')
            ->call('closeProduct')
            ->call('archiveProduct', $product->uuid)
            ->assertSet('error', '')
            ->assertDontSee('Beard Oil 50ml')
            ->set('showArchived', true)
            ->assertSee('Beard Oil 50ml');

        // Invoice prefixes: INV is the main branch's own.
        $screen->set('prefixes.'.$seed['branch']->uuid, 'x y')
            ->call('savePrefix', $seed['branch']->uuid)
            ->assertSet('error', 'An invoice prefix must be one to four letters or digits, with no spaces.');

        // Neither permission: a clear state, no product read.
        $this->actingAs($this->staffWith([Permission::SaleView], 'seller@alpha.test'), 'web');

        Livewire::test(PosSettings::class)
            ->assertViewHas('canProducts', false)
            ->assertViewHas('products', [])
            ->assertDontSee('Beard Oil');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
