@props(['values' => []])
@php
    /*
     * A 12-ish point trend inside a KPI card: de-emphasis stroke, the latest
     * point in the accent. Decorative beside the number it summarises, so it
     * is hidden from assistive technology.
     *
     * The end dot is a zero-length round-capped stroke with a non-scaling
     * width, so it stays a true circle (with its surface ring) however the
     * stretched viewBox is scaled — a <circle> would turn into an ellipse.
     */
    $values = array_values(array_map('floatval', $values));
    $count = count($values);
    $max = $count ? max($values) : 0;
    $min = $count ? min($values) : 0;
    $range = max($max - $min, 1);
    $points = [];
    foreach ($values as $i => $value) {
        $x = $count > 1 ? round($i / ($count - 1) * 100, 2) : 50;
        $y = round(26 - (($value - $min) / $range) * 22, 2);
        $points[] = $x.','.$y;
    }
    $last = $points !== [] ? explode(',', end($points)) : null;
@endphp
@if($count > 1)
    <svg {{ $attributes->class(['sparkline']) }} viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <polyline points="{{ implode(' ', $points) }}" fill="none" vector-effect="non-scaling-stroke" />
        @if($last)
            <path class="sparkline__ring" d="M{{ $last[0] }} {{ $last[1] }}h0" vector-effect="non-scaling-stroke" />
            <path class="sparkline__dot" d="M{{ $last[0] }} {{ $last[1] }}h0" vector-effect="non-scaling-stroke" />
        @endif
    </svg>
@endif
