{{--
    One service card.

    Every field here is read from the model directly and escaped by Blade. The
    query loaded only publicly-visible rows, so there is nothing internal in
    scope to render by accident. How the card looks (image ratio, where the
    price sits, the button style, dense rows) is decided by the classes on
    <body>, never here.
--}}
<article class="service">
    @if (($image = $service->primaryMedia()) && $image->url())
        <img src="{{ $image->url() }}"
             alt="{{ $image->alt_text?->get($locale) ?? $service->name?->get($locale) }}"
             loading="lazy" decoding="async">
    @endif

    <div class="meta">
        <h3>{{ $service->name?->get($locale) }}</h3>
        <span class="price">{{ $service->price($menu['currency'])->formatted($locale) }}</span>
    </div>

    @if ($service->short_description)
        <p class="desc">{{ $service->short_description->get($locale) }}</p>
    @endif

    <p class="desc">{{ __('menu_public.minutes', ['count' => $service->duration_minutes]) }}</p>

    @if ($service->variations->isNotEmpty())
        <ul class="variants">
            @foreach ($service->variations as $variation)
                <li>
                    <span>{{ $variation->name?->get($locale) }}</span>
                    {{-- Resolved, never raw: a blank price would show where a
                         variation inherits from its service (ADR-037). --}}
                    <span>{{ $variation->effectivePrice($service, $menu['currency'])->formatted($locale) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($service->addons->isNotEmpty())
        <p class="addons">
            {{ __('menu_public.addons') }}:
            @foreach ($service->addons as $addon)
                {{ $addon->name?->get($locale) }} ({{ $addon->price($menu['currency'])->formatted($locale) }})@if (! $loop->last), @endif
            @endforeach
        </p>
    @endif

    {{--
        The Book action.

        Rendered only when the center owns `booking` AND the service is marked
        online-bookable. A center that lists a service for information and takes
        those bookings by phone gets no button at all — showing one that leads
        to a refusal would be worse than showing none
        (docs/13-ROADMAP.md Phase 6 §§26, 31).
    --}}
    @if ($bookable && $service->is_online_bookable)
        <p class="actions">
            <a class="book" href="{{ route('menu.book', array_filter([
                'center' => $centerKey,
                'branch' => $menu['branch']?->uuid,
                'service' => $service->uuid,
            ])) }}">{{ __('menu_public.book') }}</a>
        </p>
    @endif
</article>
