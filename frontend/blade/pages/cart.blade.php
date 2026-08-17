@extends('layout')

@section('content')
{{-- The order summary. Lines, options, mode, fee, tax and total are priced by the core
     Cart section; the full restaurant checkout — mode picker, address or pickup, scheduled
     time — is phase 4.

     The `checkout` plugin slot is **not** called here, and that is deliberate. It belongs
     in the discount position, which is core's Cart section, and since 2026-08-16 that
     section renders the slot itself. This theme used to carry a copy directly under the
     cart because core offered no seam (audit A15); keeping it now would put two elements
     with the same `data-checkout-field` on the page, and `collectPluginFields()` keeps
     whichever it reads last. --}}
<div class="saffron-cart">
    {{-- ASAP or a scheduled slot, from the shop's own hours. Rendered BEFORE the cart, never
         after: a control that changes the order must be met before the button that places it,
         and the summary card holding that button is core's Cart section, which a theme can
         only precede or follow. The component seats itself directly above Proceed to Checkout
         once that button exists; this position is the floor it falls back to, and it is still
         ahead of the button. --}}
    <div class="saffron-container">
        {{-- Delivery or pickup, then when. Both seat themselves inside core's order summary
             above Proceed to Checkout; this is the floor they fall back to, and it is still
             ahead of the button. Mode first, because it decides whether the address form the
             summary draws is wanted at all. --}}
        <x-theme.component name="OrderMode" />
        <x-theme.component name="OrderSchedule" />
    </div>

    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-container saffron-section">
            <h1 class="saffron-section-title mb-3">{{ __('Your order') }}</h1>
            <div class="saffron-menu__empty">
                {{-- Neutral: this branch means the CART page has no builder rows, not that
                     the cart is empty — the cart lives in the browser and this template
                     cannot see it (audit A21). --}}
                <p class="mb-0">{{ __('Your order will appear here.') }}</p>
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
</div>
@endsection
