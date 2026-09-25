{{--
    Usage: what this center has used of its allowances, this period.

    docs/26-USAGE-QUOTAS.md §14. Functional only.

    Deliberately absent: any unit cost, any provider price, any Meta Style
    margin. A center is shown what THEY used against what THEY bought; the
    platform's economics are not theirs to read.
--}}
@php
    $toneFor = fn (string $status) => match ($status) { 'exhausted' => 'danger', 'high', 'warning' => 'warning', default => 'success' };
@endphp

<div class="stack">
    <x-ui.page-header :title="__('usage.title')" />

    @if(! $allowed)
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="lock" /><p>{{ __('usage.not_allowed') }}</p></div>
    @else
        @foreach($groups as $name => $resources)
            <x-ui.card :title="__('usage.group_'.$name)" flush>
                @if($resources === [])
                    <x-ui.empty-state compact icon="usage" :title="__('usage.none')" />
                @else
                    <div class="usage-tiles">
                        @foreach($resources as $resource)
                            @php $tone = $toneFor($resource['status']); @endphp
                            <article class="usage-tile" data-tone="{{ $resource['enforced'] ? $tone : 'neutral' }}">
                                <div class="usage-tile__head">
                                    <span class="usage-tile__name">{{ __('usage.resource_'.$resource['resource']) }}</span>
                                    @if($resource['enforced'])
                                        <x-ui.status :tone="$tone" :label="__('usage.status_'.$resource['status'])" />
                                    @else
                                        {{-- Metered only: counted, never refused (§3). --}}
                                        <x-ui.status tone="neutral" :label="__('usage.metered_only')" :dot="false" />
                                    @endif
                                </div>
                                <p class="usage-tile__value">
                                    <strong class="tabular">{{ number_format((int) $resource['used']) }}</strong>
                                    {{-- UNLIMITED is its own word, never a large number (§4). --}}
                                    <span class="muted">/ {{ $resource['unlimited'] ? __('usage.unlimited') : number_format((int) $resource['allowance']) }}</span>
                                </p>
                                @unless($resource['unlimited'])
                                    <div class="progress" data-tone="{{ $tone }}" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, (int) $resource['percent']) }}" aria-label="{{ __('usage.resource_'.$resource['resource']) }}">
                                        <div class="progress__bar" style="width: {{ min(100, (int) $resource['percent']) }}%"></div>
                                    </div>
                                    <p class="usage-tile__meta">{{ __('usage.remaining') }}: <span class="tabular">{{ number_format((int) $resource['remaining']) }}</span></p>
                                @endunless
                                <p class="usage-tile__meta">{{ __('usage.resets') }}: <time datetime="{{ $resource['period_end'] }}">{{ \Illuminate\Support\Carbon::parse($resource['period_end'])->translatedFormat('j M Y') }}</time></p>
                            </article>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    @endif
</div>
