{{--
    POS settings: the products the till sells and each branch's invoice prefix.
    docs/18-SALES.md §§6, 17. Names per enabled content language; prices typed
    in the center's currency and parsed by the server.
--}}
<div class="stack pos-page">
    <x-ui.page-header :title="__('manager_pos.settings.title')" :breadcrumbs="[['label' => __('ui.manager_nav.items.pos'), 'href' => route('center.pos')], ['label' => __('manager_pos.settings.title')]]">
        @if($canProducts && ! $editingProduct)
            <x-slot:actions>
                <button class="button" type="button" wire:click="newProduct"><x-ui.icon name="plus" size="16" />{{ __('Add product') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if($error !== '' && ! $editingProduct)<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
    @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif

    @if(! $canProducts && ! $canPrefix)
        <x-ui.empty-state icon="lock" :title="__('manager_finance.denied.title')" :description="__('manager_pos.settings.denied')" />
    @endif

    @if($canProducts)
        <x-ui.card :title="__('Products')" flush>
            <div class="pos-card-bar">
                <div class="search-input grow">
                    <x-ui.icon name="search" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('manager_pos.settings.search') }}" aria-label="{{ __('manager_pos.settings.search') }}" autocomplete="off">
                </div>
                <label class="choice"><input type="checkbox" wire:model.live="showArchived"><span>{{ __('manager_pos.settings.show_archived') }}</span></label>
            </div>
            @if($products === [])
                <x-ui.empty-state compact icon="tag" :title="$search !== '' ? __('manager_pos.catalog.no_match') : __('No products yet.')">
                    @if($search === '' && ! $showArchived && ! $editingProduct)<button class="button button--sm" type="button" wire:click="newProduct">{{ __('Add product') }}</button>@endif
                </x-ui.empty-state>
            @else
                <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,showArchived,gotoPage,nextPage,previousPage">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col">{{ __('manager_pos.settings.codes') }}</th>
                                <th scope="col" class="numeric">{{ __('Price') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($products as $product)
                                <tr wire:key="product-{{ $product['uuid'] }}" @if($product['archived'] || ! $product['active']) data-muted="true" @endif>
                                    <td data-label="{{ __('Name') }}" data-primary><span class="cell-title">{{ $product['name'] }}</span></td>
                                    <td data-label="{{ __('manager_pos.settings.codes') }}">
                                        @if($product['barcode'] !== null)<span class="cell-sub" dir="ltr">{{ $product['barcode'] }}</span>@endif
                                        @if($product['sku'] !== null)<span class="cell-sub" dir="ltr">{{ __('manager_pos.settings.sku', ['sku' => $product['sku']]) }}</span>@endif
                                        @if($product['barcode'] === null && $product['sku'] === null)<span class="muted">—</span>@endif
                                    </td>
                                    <td data-label="{{ __('Price') }}" class="numeric"><span dir="ltr" class="tabular">{{ $product['price'] }}</span></td>
                                    <td data-label="{{ __('Status') }}">
                                        @if($product['archived'])
                                            <x-ui.status value="archived" :label="__('ui.states.archived')" />
                                        @else
                                            <x-ui.status :value="$product['active'] ? 'active' : 'inactive'" :label="$product['active'] ? __('manager_pos.settings.on_sale') : __('manager_pos.settings.off_sale')" />
                                        @endif
                                    </td>
                                    <td class="actions">
                                        @unless($product['archived'])
                                            <button class="button button--ghost button--sm" type="button" wire:click="editProduct('{{ $product['uuid'] }}')"><x-ui.icon name="edit" size="14" />{{ __('ui.actions.edit') }}</button>
                                            <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="archiveProduct('{{ $product['uuid'] }}')" wire:confirm="{{ __('Archive this product? Past sales keep it.') }}" data-confirm-title="{{ __('ui.actions.archive') }}" data-confirm-tone="danger" aria-label="{{ __('ui.actions.archive') }}" title="{{ __('ui.actions.archive') }}"><x-ui.icon name="archive" /></button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($paginator !== null)
                    <div class="pos-card-bar">{{ $paginator->links() }}</div>
                @endif
            @endif
        </x-ui.card>
    @endif

    @if($canPrefix && $branches !== [])
        <x-ui.card :title="__('Invoice numbering')" flush>
            <x-slot:actions>
                <span class="info-tip" title="{{ __('manager_pos.settings.prefix_hint') }}" aria-label="{{ __('manager_pos.settings.prefix_hint') }}" role="img"><x-ui.icon name="info" size="16" /></span>
            </x-slot:actions>
            <ul class="row-list">
                @foreach($branches as $branchRow)
                    <li class="row-list__item pos-prefix" wire:key="prefix-{{ $branchRow['uuid'] }}">
                        <div class="row-list__body">
                            <span class="cell-title">{{ $branchRow['name'] }}@if($branchRow['is_main']) <span class="badge">{{ __('manager_pos.settings.main_branch') }}</span>@endif</span>
                            <span class="cell-sub">
                                @if($branchRow['effective'] !== '')
                                    {{ __('manager_pos.settings.prefix_now') }} <span dir="ltr" class="mono">{{ $branchRow['effective'] }}-…</span>
                                @else
                                    <span class="text-warning">{{ __('manager_pos.settings.prefix_missing') }}</span>
                                @endif
                            </span>
                        </div>
                        <form class="cluster cluster--tight" wire:submit="savePrefix('{{ $branchRow['uuid'] }}')">
                            <label class="sr-only" for="prefix-{{ $branchRow['uuid'] }}">{{ __('Invoice numbering') }} · {{ $branchRow['name'] }}</label>
                            <input id="prefix-{{ $branchRow['uuid'] }}" class="input--prefix" type="text" dir="ltr" wire:model="prefixes.{{ $branchRow['uuid'] }}" maxlength="4" placeholder="{{ $branchRow['is_main'] ? 'INV' : __('e.g. BG') }}" autocomplete="off">
                            <button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="savePrefix">{{ __('Save prefix') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if($editingProduct && $canProducts)
        <x-ui.drawer :title="$productUuid === '' ? __('Add product') : __('manager_pos.settings.edit_product')" close="closeProduct" submit="saveProduct">
            <div class="stack">
                @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
                @foreach($productLocales as $field)
                    <x-ui.field :label="__('Name').' · '.$field['label']" for="product-name-{{ $field['locale'] }}" name="productNames.{{ $field['locale'] }}" :required="$field['primary']">
                        <input id="product-name-{{ $field['locale'] }}" type="text" dir="{{ $field['dir'] }}" lang="{{ $field['locale'] }}" wire:model="productNames.{{ $field['locale'] }}" maxlength="120" @required($field['primary'])>
                    </x-ui.field>
                @endforeach
                <div class="form-grid">
                    <x-ui.field :label="__('Price')" for="product-price" name="productPrice" required>
                        <div class="input-affix">
                            <input id="product-price" type="text" dir="ltr" inputmode="decimal" wire:model="productPrice" required autocomplete="off">
                            <span class="input-affix__suffix" dir="ltr">{{ $currency }}</span>
                        </div>
                    </x-ui.field>
                    <x-ui.field :label="__('Barcode (optional)')" for="product-barcode" name="productBarcode">
                        <input id="product-barcode" type="text" dir="ltr" wire:model="productBarcode" maxlength="64" autocomplete="off">
                    </x-ui.field>
                    <x-ui.field :label="__('SKU (optional)')" for="product-sku" name="productSku">
                        <input id="product-sku" type="text" dir="ltr" wire:model="productSku" maxlength="64" autocomplete="off">
                    </x-ui.field>
                </div>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="productActive"><span>{{ __('manager_pos.settings.on_sale_switch') }}</span></label>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeProduct">{{ __('Cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveProduct"><x-ui.icon name="save" size="16" />{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
