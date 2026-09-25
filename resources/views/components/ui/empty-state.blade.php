@props([
    'title',
    'description' => null,
    'icon' => 'layers',
    'compact' => false,
])
<div {{ $attributes->class(['empty-state', 'empty-state--compact' => $compact]) }}>
    <span class="empty-state__icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span>
    <strong>{{ $title }}</strong>
    @if($description)<p>{{ $description }}</p>@endif
    {{ $slot }}
</div>
