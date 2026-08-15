@extends('layout')

@section('content')
{{-- The order summary. Lines, options, mode, fee, tax and total are priced by the core
     Cart section; the full restaurant checkout — mode picker, address or pickup, scheduled
     time — is phase 4.

     The `checkout` plugin slot is mandatory: a plugin ships controls that reduce the total
     and owns no template, so a theme that omits the slot silently drops every storefront
     feature the shop has paid for. It sits beside the summary, in the discount position,
     because that is where the server prices the adjustment. --}}
<div class="saffron-cart">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-container saffron-section">
            <h1 class="saffron-section-title mb-3">{{ __('Your order') }}</h1>
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('Your order is empty.') }}</p>
                @if(config('app.debug'))
                    <p class="small mb-0 mt-2">
                        {{ __('The CART page has no builder rows. Add a Cart section to it.') }}
                    </p>
                @endif
            </div>
            <div class="mt-4">
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--accent">{{ __('Browse the menu') }}</a>
            </div>
        </div>
    @endif

    <div class="saffron-container">
        <x-plugin-slot name="checkout" :data="['screen' => 'cart']" />
    </div>
</div>
@endsection
