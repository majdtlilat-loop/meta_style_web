@if ($menu['categories']->isNotEmpty())
    <section>
        <h2>{{ __('menu_public.categories') }}</h2>
        <div class="cats {{ $config['layout'] ?? 'grid' }}">
            @foreach ($menu['categories'] as $category)
                <a class="cat" href="#category-{{ $category->uuid }}">
                    @if (($config['show_images'] ?? false) && ($image = $category->primaryMedia()) && $image->url())
                        <img src="{{ $image->url() }}" alt="" loading="lazy" decoding="async">
                    @endif
                    <span>
                        <strong>{{ $category->name?->get($locale) }}</strong>
                        @if ($category->description)
                            <span class="desc">{{ $category->description->get($locale) }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endif
