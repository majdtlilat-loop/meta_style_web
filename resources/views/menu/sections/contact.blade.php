{{-- The branch's published business contact details — never a customer's. --}}
@if ($menu['branch'] && (
    (($config['show_phone'] ?? true) && $menu['contact']['phone'] !== null)
    || (($config['show_whatsapp'] ?? true) && $menu['contact']['whatsapp'] !== null)
    || (($config['show_email'] ?? true) && $menu['contact']['email'] !== null)
))
    <footer>
        <ul class="contact-links" aria-label="{{ __('menu_public.contact') }}">
            @if (($config['show_phone'] ?? true) && $menu['contact']['phone'] !== null)
                <li><a href="tel:{{ $menu['contact']['phone'] }}" dir="ltr">{{ $menu['contact']['phone'] }}</a></li>
            @endif
            @if (($config['show_whatsapp'] ?? true) && $menu['contact']['whatsapp'] !== null)
                <li><a href="https://wa.me/{{ $menu['contact']['whatsapp'] }}" target="_blank" rel="noopener nofollow">{{ __('menu_public.whatsapp') }}</a></li>
            @endif
            @if (($config['show_email'] ?? true) && $menu['contact']['email'] !== null)
                <li><a href="mailto:{{ $menu['contact']['email'] }}" dir="ltr">{{ $menu['contact']['email'] }}</a></li>
            @endif
        </ul>
    </footer>
@endif
