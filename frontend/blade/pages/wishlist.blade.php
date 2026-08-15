@extends('layout')

@section('content')
{{-- A saved-dishes list. Backed by window.OvyntStore's wishList in localStorage, so it is
     device-local until a customer signs in. --}}
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-section">
            <h1 class="saffron-section-title mb-3">{{ __('Saved dishes') }}</h1>
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('You have not saved any dishes yet.') }}</p>
            </div>
            <div class="mt-4">
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--accent">{{ __('Browse the menu') }}</a>
            </div>
        </div>
    @endif
</div>
@endsection
