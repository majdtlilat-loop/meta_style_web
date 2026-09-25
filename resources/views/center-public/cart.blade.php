{{--
    The cart page. No cart backend exists yet (checkout is a later phase), so
    this is an honest empty state in the center's own words and colours —
    never an invented line, count or total. Appearance comes validated from
    PublicPageAppearance::cart(); the Manager preview renders this template
    with `$preview`.
--}}
@extends('layouts.center-public.app')
@section('title', $appearance['heading'])
@section('content')
    @include('center-public.partials.cart-style')
    <section class="cart-page {{ implode(' ', $appearance['classes']) }}" style="@foreach ($appearance['vars'] as $property => $value){{ $property }}: {{ $value }}; @endforeach">
        @if ($preview ?? false)
            <p class="cart-preview-banner" role="note">{{ __('menu_public.cart.preview_banner') }}</p>
        @endif
        <div class="cart-page__grid">
            <div class="cart-page__main">
                @if ($appearance['logo'])
                    <img class="cart-page__logo" src="{{ $appearance['logo'] }}" alt="{{ $center->name }}" height="44">
                @endif
                <h1>{{ $appearance['heading'] }}</h1>
                @if ($appearance['intro'])
                    <p class="cart-page__intro">{{ $appearance['intro'] }}</p>
                @endif

                <div class="cart-empty">
                    <span class="cart-empty__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.6l2.4 11.2a1.6 1.6 0 0 0 1.6 1.3h8.3a1.6 1.6 0 0 0 1.6-1.2l1.6-6.8H6.2"/></svg>
                    </span>
                    <h2>{{ $appearance['empty_title'] }}</h2>
                    <p>{{ $appearance['empty_body'] }}</p>
                    <a class="cart-cta" href="{{ route('menu.public', ['center' => request()->route('center')]) }}">{{ $appearance['cta_label'] }}</a>
                </div>

                @if ($appearance['summary'] === 'inline')
                    <div class="cart-summary cart-summary--inline">
                        <h2>{{ __('menu_public.cart.summary') }}</h2>
                        <p>{{ __('menu_public.cart.summary_empty') }}</p>
                    </div>
                @endif
            </div>

            @if ($appearance['summary'] === 'panel')
                <aside class="cart-summary" aria-label="{{ __('menu_public.cart.summary') }}">
                    <h2>{{ __('menu_public.cart.summary') }}</h2>
                    <p>{{ __('menu_public.cart.summary_empty') }}</p>
                </aside>
            @endif
        </div>
    </section>
@endsection
