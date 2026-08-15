@extends('layout')

@section('content')
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-status">
            <div class="saffron-status__code">500</div>
            <h1 class="saffron-status__title">{{ __('Something went wrong in the kitchen') }}</h1>
            <p class="saffron-status__text">
                {{ __('We hit an error on our side. Nothing was charged. Please try again in a moment.') }}
            </p>
            <div class="saffron-status__actions">
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--accent">{{ __('Back to the menu') }}</a>
            </div>
        </div>
    @endif
</div>
@endsection
