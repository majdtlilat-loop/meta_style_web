{{--
    The checkout page. Online checkout is a later phase: with no cart backend
    there is nothing to pay for, so the page says what happens today in the
    center's own words and sends the customer back — no form, no totals.
--}}
@extends('layouts.center-public.app')
@section('title', $appearance['checkout_heading'])
@section('content')
    @include('center-public.partials.cart-style')
    <section class="cart-page cart-layout-centered {{ implode(' ', $appearance['classes']) }}" style="@foreach ($appearance['vars'] as $property => $value){{ $property }}: {{ $value }}; @endforeach">
        <div class="cart-page__main">
            @if ($appearance['logo'])
                <img class="cart-page__logo" src="{{ $appearance['logo'] }}" alt="{{ $center->name }}" height="44">
            @endif
            <h1>{{ $appearance['checkout_heading'] }}</h1>
            <div class="cart-empty">
                <p>{{ $appearance['checkout_body'] }}</p>
                <a class="cart-cta" href="{{ route('center.cart', ['center' => request()->route('center')]) }}">{{ __('menu_public.cart.back_to_cart') }}</a>
            </div>
        </div>
    </section>
@endsection
