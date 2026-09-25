{{-- The money workspace's tabs. $tabs comes from PosFinance\MoneyTabs. --}}
@if($tabs !== [])
    <nav class="pos-tabs" aria-label="{{ __('manager_finance.tabs.label') }}">
        @foreach($tabs as $tab)
            <a class="pos-tabs__tab" href="{{ $tab['href'] }}" wire:navigate @if($tab['active']) aria-current="page" @endif>
                <x-ui.icon :name="$tab['icon']" size="16" /><span>{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </nav>
@endif
