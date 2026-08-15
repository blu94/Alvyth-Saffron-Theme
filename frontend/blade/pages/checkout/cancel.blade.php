@extends('layout')

@section('content')
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-status">
            <div class="saffron-status__code">&#8635;</div>
            <h1 class="saffron-status__title">{{ __('Checkout cancelled') }}</h1>
            <p class="saffron-status__text">
                {{ __('Nothing was charged and your order is still in the cart.') }}
            </p>
            <div class="saffron-status__actions">
                <a href="{{ url('/cart') }}" class="saffron-btn saffron-btn--accent">{{ __('Back to your order') }}</a>
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--outline">{{ __('Browse the menu') }}</a>
            </div>
        </div>
    @endif
</div>
@endsection
