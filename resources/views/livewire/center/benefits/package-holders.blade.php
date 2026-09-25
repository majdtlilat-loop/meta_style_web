{{-- Who holds an active package, and what is left. Rows from PackagesPresenter::holders(). --}}
<div>
    <x-ui.card :title="__('manager_benefits.packages.holders_title')" flush>
        <div class="benefits-search">
            <label class="sr-only" for="package-holder-search">{{ __('ui.fields.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="package-holder-search" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('manager_benefits.members.search') }}" autocomplete="off" maxlength="120">
            </div>
        </div>
        <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,gotoPage,nextPage,previousPage">
            @if($holders === [])
                <x-ui.empty-state compact icon="packages" :title="$search !== '' ? __('manager_benefits.members.no_match') : __('manager_benefits.packages.no_holders')" />
            @else
                <table>
                    <caption class="sr-only">{{ __('manager_benefits.packages.holders_title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_benefits.members.customer') }}</th>
                            <th scope="col">{{ __('manager_benefits.members.package') }}</th>
                            <th scope="col">{{ __('manager_benefits.packages.left_title') }}</th>
                            <th scope="col">{{ __('manager_benefits.members.until') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($holders as $holder)
                            <tr wire:key="package-holder-{{ $holder['uuid'] }}">
                                <td data-label="{{ __('manager_benefits.members.customer') }}" data-primary>
                                    @if($holder['url'])<a class="cell-title" href="{{ $holder['url'] }}" wire:navigate>{{ $holder['customer']['name'] }}</a>@else<span class="cell-title">—</span>@endif
                                </td>
                                <td data-label="{{ __('manager_benefits.members.package') }}">{{ $holder['name'] }}</td>
                                <td data-label="{{ __('manager_benefits.packages.left_title') }}">
                                    @foreach($holder['items'] as $item)
                                        <span class="cell-sub">{{ $item['name'] }}@if($item['variation'] !== null) · {{ $item['variation'] }}@endif — <strong class="tabular">{{ __('manager_benefits.panel.left', ['n' => $item['left'], 'm' => $item['allocated']]) }}</strong></span>
                                    @endforeach
                                </td>
                                <td data-label="{{ __('manager_benefits.members.until') }}">{{ $holder['last_day_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @if($page->hasPages())
            <footer class="card__footer">{{ $page->links() }}</footer>
        @endif
    </x-ui.card>
</div>
