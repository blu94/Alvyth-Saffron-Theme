@extends('layout')

@section('content')
{{-- Where the payment gateway lands a paid customer. Carried by the core Checkout Success
     section, which reads the order reference from the redirect.

     The order tracker and a real ETA are phase 9: an accurate "ready by" needs the
     scheduled time and ordering mode persisted on the order, and core discards both today
     (RESTAURANT-THEME-SPEC.md §14 item 2). --}}
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @php
            // `prep_time_label` is translatable, so a saved value arrives as a locale-keyed
            // array — echoing it raw throws the moment the Restaurant tab is first saved,
            // and this is the page a customer lands on having just paid. Resolved the same
            // way the header resolves announcement_text.
            $prepRaw  = $settings['prep_time_label'] ?? null;
            $prepLoc  = $locale ?? app()->getLocale();
            $prepText = is_array($prepRaw)
                ? ($prepRaw[$prepLoc] ?? $prepRaw['en'] ?? (count($prepRaw) ? reset($prepRaw) : ''))
                : (string) ($prepRaw ?? '');
        @endphp
        <div class="saffron-status">
            <div class="saffron-status__code">&#10003;</div>
            <h1 class="saffron-status__title">{{ __('Order received') }}</h1>
            <p class="saffron-status__text">
                {{ $prepText !== '' ? $prepText : __('We are getting started on it now.') }}
            </p>
            <div class="saffron-status__actions">
                <a href="{{ url('/profile') }}" class="saffron-btn saffron-btn--accent">{{ __('View your orders') }}</a>
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--outline">{{ __('Back to the menu') }}</a>
            </div>
        </div>
    @endif
</div>

<script>
{{-- The fallback branch above renders without the core Checkout Success section, so the
     cart clearing that section carries never runs on it. Cleared here too — idempotent
     when both run, and a paid order must never leave its items counting in the badge. --}}
(function () {
    // **Only for a visitor who has just paid.** This page is a URL like any other: it sits in
    // history and in bookmarks, so clearing unconditionally emptied the basket of somebody who
    // pressed Back into it while building a new order, with nothing on screen explaining where
    // their food had gone. The redirect from checkout carries the order, so its absence means
    // this is a revisit and there is nothing of ours to clear.
    var params = new URLSearchParams(window.location.search);

    if (!params.get('order') && !params.get('order_id') && !params.get('session_id')) {
        return;
    }

    var tries = 0;
    var timer = setInterval(function () {
        if (window.AlvythStore) {
            window.AlvythStore.cartList.splice(0, window.AlvythStore.cartList.length);
            window.AlvythStore.saveCart();
            clearInterval(timer);
        } else if (++tries > 30) {
            // Wrapped: a browser set to block site data throws here, and an unwrapped throw
            // skips the clearInterval below it and leaves this running every 100ms forever.
            try {
                localStorage.setItem('alvyth_cart', '[]');
            } catch (e) { /* blocked storage: the badge is wrong until the cart page reprices */ }

            clearInterval(timer);
        }
    }, 100);
})();
</script>
@endsection
