@extends('layout')

@section('content')
{{-- Two routes reach this template: a 404 Page record laid out in the builder, and the
     hard-404 path, which renders with only $themeConfig — no $page, no $settings, no
     $locale. Everything here must survive both.

     The `not-found` plugin slot is rendered OUTSIDE the branch that draws the page. A 404
     built from builder rows and one using the theme's own message are the same dead URL to
     a visitor, and a plugin suggesting a destination should be reached either way. --}}
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-status">
            <div class="saffron-status__code">404</div>
            <h1 class="saffron-status__title">{{ __('We could not find that') }}</h1>
            <p class="saffron-status__text">
                {{ __('That page has moved or never existed. The menu is still where you left it.') }}
            </p>
            <div class="saffron-status__actions">
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--accent">{{ __('Browse the menu') }}</a>
                <a href="{{ url('/cart') }}" class="saffron-btn saffron-btn--outline">{{ __('Your order') }}</a>
            </div>
        </div>
    @endif

    <div class="saffron-status__slot">
        <x-plugin-slot name="not-found" :data="['path' => request()->path()]" />
    </div>
</div>
@endsection
