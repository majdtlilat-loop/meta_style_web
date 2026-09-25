{{-- A section backdrop from brand tokens or site media. Params: $background. --}}
@if($background['type'] === 'image' && $background['image'])
    <div class="cs-bg" aria-hidden="true">
        <img class="cs-bg__media" src="{{ $background['image'] }}" alt="" loading="lazy">
        <span class="cs-bg__overlay" data-overlay="{{ $background['overlay'] }}"></span>
    </div>
@elseif($background['type'] === 'video' && $background['video'])
    <div class="cs-bg" aria-hidden="true">
        <video class="cs-bg__media" autoplay muted loop playsinline preload="metadata" @if($background['video']['poster']) poster="{{ $background['video']['poster'] }}" @endif><source src="{{ $background['video']['url'] }}"></video>
        <span class="cs-bg__overlay" data-overlay="{{ $background['overlay'] }}"></span>
    </div>
@endif
