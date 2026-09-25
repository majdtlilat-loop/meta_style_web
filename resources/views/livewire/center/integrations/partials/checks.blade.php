{{-- The setup check: recorded facts only, never a live provider call. --}}
<section class="card card--flush wa-checks" aria-labelledby="wa-checks-title" wire:loading.class="is-refreshing" wire:target="checkSetup">
    <header class="card__header">
        <div>
            <h2 id="wa-checks-title">
                {{ __('manager_whatsapp.check.title') }}
                <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_whatsapp.check.note') }}" aria-label="{{ __('manager_whatsapp.check.note') }}"><x-ui.icon name="info" size="16" /></span>
            </h2>
            @if($checks['checked'])<p>{{ $checks['checked'] }}</p>@endif
        </div>
        <x-ui.status :tone="$checks['tone']" :label="$checks['label']" />
    </header>
    <ul class="wa-checklist" role="list">
        @foreach($checks['items'] as $item)
            <li class="wa-checklist__item" data-state="{{ $item['state'] }}" wire:key="wa-check-{{ $item['key'] }}">
                <span class="wa-checklist__icon" data-tone="{{ $item['tone'] }}" aria-hidden="true"><x-ui.icon :name="$item['icon']" size="16" /></span>
                <span class="wa-checklist__body">
                    <span class="wa-checklist__label">{{ $item['label'] }}</span>
                    @if($item['detail'])<span class="cell-sub">{{ $item['detail'] }}</span>@endif
                </span>
                <span class="sr-only">{{ __('manager_whatsapp.check.item_states.'.$item['state']) }}</span>
            </li>
        @endforeach
    </ul>
</section>
