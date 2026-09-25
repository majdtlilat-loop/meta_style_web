@extends('layouts.center-public.app')

@section('content')
    @if($page['hero'])
        @include('center-public.partials.hero', ['hero' => $page['hero']])
    @endif

    @foreach($page['sections'] as $section)
        @include('center-public.partials.section', ['section' => $section])
    @endforeach

    @if(! $page['hero'] && $page['sections'] === [])
        <section class="cs-section cs-empty-page">
            <div class="cs-container cs-heading cs-heading--center">
                <h1>{{ $page['brand']['name'] }}</h1>
            </div>
        </section>
    @endif
@endsection
