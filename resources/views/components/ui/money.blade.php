@props(['minor' => 0, 'currency' => 'IQD'])
@php
    /*
     * Display only — IQD shows no decimals, USD two, and any other catalog
     * currency its own configured decimals. Never parse this back into an
     * amount.
     */
    $text = app(\App\Kernel\Platform\Currencies\PlatformCurrencies::class)->format((int) $minor, strtoupper((string) $currency), app()->getLocale());
@endphp
<span {{ $attributes->class(['tabular', 'nowrap']) }} dir="ltr">{{ $text }}</span>
