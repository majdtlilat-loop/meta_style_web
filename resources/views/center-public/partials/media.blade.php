{{-- A section's own image or video. Params: $media {image, video, position}. --}}
@if($media['video'])
    <figure class="cs-media"><video controls muted playsinline preload="metadata" @if($media['video']['poster']) poster="{{ $media['video']['poster'] }}" @endif @if($media['image']) aria-label="{{ $media['image']['alt'] }}" @endif><source src="{{ $media['video']['url'] }}"></video></figure>
@elseif($media['image'])
    <figure class="cs-media"><img src="{{ $media['image']['url'] }}" alt="{{ $media['image']['alt'] }}" loading="lazy"></figure>
@endif
