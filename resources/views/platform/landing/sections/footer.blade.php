@php
    use App\View\Landing;

    $enabledLinks = fn (array $links): array => array_values(array_filter($links, fn (array $link): bool => ($link['enabled'] ?? true) && Landing::text($link['label'] ?? []) !== '' && ($link['url'] ?? '') !== ''));
    $socialIcons = ['facebook' => 'facebook', 'instagram' => 'instagram', 'x' => 'x-brand', 'linkedin' => 'linkedin', 'youtube' => 'youtube', 'tiktok' => 'tiktok', 'whatsapp' => 'message', 'telegram' => 'send', 'website' => 'globe'];
    $social = array_values(array_filter($footer['social_links'] ?? [], fn (array $link): bool => ($link['enabled'] ?? true) && ($link['url'] ?? '') !== ''));
    $quickLinks = $enabledLinks($footer['navigation'] ?? []);
    $legal = $enabledLinks($footer['legal_links'] ?? []);
    $groups = array_values(array_filter(array_map(fn (array $group): array => ['title' => Landing::text($group['title'] ?? []), 'links' => $enabledLinks($group['links'] ?? [])], $footer['groups'] ?? []), fn (array $group): bool => $group['links'] !== []));
    $description = Landing::text($footer['description'] ?? []);
    $address = Landing::text($footer['address'] ?? []);
    $email = (string) ($footer['email'] ?? '');
    $phone = (string) ($footer['phone'] ?? '');
@endphp
@if($footer['enabled'] ?? true)
    <footer class="corporate-footer">
        <div class="landing-container">
            <div class="corporate-footer__grid">
                <div class="corporate-footer__brand">
                    @if($footer['show_logo'] ?? true)<x-brand.logo :href="route('home')" />@endif
                    @if($description !== '')<p>{{ $description }}</p>@endif
                    @if($email !== '' || $phone !== '' || $address !== '')
                        <ul class="corporate-footer__contact" role="list">
                            @if($email !== '')<li><x-ui.icon name="mail" :size="16" /><a class="ltr" href="mailto:{{ $email }}">{{ $email }}</a></li>@endif
                            @if($phone !== '')<li><x-ui.icon name="phone" :size="16" /><a class="ltr" dir="ltr" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></li>@endif
                            @if($address !== '')<li><x-ui.icon name="map-pin" :size="16" /><span>{{ $address }}</span></li>@endif
                        </ul>
                    @endif
                    @if($social !== [])
                        <ul class="corporate-footer__social" role="list">
                            @foreach($social as $link)
                                @php $label = Landing::text($link['label'] ?? [], __('platform_landing.social.'.$link['network'])); @endphp
                                <li><a class="icon-button" href="{{ $link['url'] }}" target="_blank" rel="noopener" aria-label="{{ $label }}" title="{{ $label }}"><x-ui.icon :name="$socialIcons[$link['network']] ?? 'globe'" :size="18" /></a></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @if($quickLinks !== [])
                    <nav class="corporate-footer__group" aria-label="{{ __('platform_landing.footer.quick_links') }}">
                        <h2>{{ __('platform_landing.footer.quick_links') }}</h2>
                        <ul role="list">
                            @foreach($quickLinks as $link)
                                <li><a href="{{ Landing::href($link) }}" @if($link['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($link['label']) }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
                @foreach($groups as $group)
                    <nav class="corporate-footer__group" aria-label="{{ $group['title'] }}">
                        <h2>{{ $group['title'] }}</h2>
                        <ul role="list">
                            @foreach($group['links'] as $link)
                                <li><a href="{{ Landing::href($link) }}" @if($link['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($link['label']) }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
            </div>
            <div class="corporate-footer__bottom">
                <p>© {{ now()->year }} {{ Landing::text($footer['copyright'] ?? [], 'Meta Style') }}</p>
                @if($legal !== [])
                    <ul class="corporate-footer__legal" role="list">
                        @foreach($legal as $link)
                            <li><a href="{{ Landing::href($link) }}" @if($link['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($link['label']) }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </footer>
@endif
