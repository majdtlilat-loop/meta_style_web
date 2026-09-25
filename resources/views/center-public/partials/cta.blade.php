{{-- One call to action. Params: $cta {label, href, style, new_tab}. --}}
<a class="cs-button cs-button--{{ $cta['style'] }}" href="{{ $cta['href'] }}" @if($cta['new_tab']) target="_blank" rel="noopener" @endif>{{ $cta['label'] }}</a>
