@extends('layout')

@section('content')
{{-- A saved-dishes list. Backed by window.OvyntStore's wishList in localStorage, so it is
     device-local until a customer signs in. The server never sees the list and cannot
     resolve anything for it, which is why each line carries its own url — put there by the
     heart on the dish card and the dish sheet. --}}
@php
    // The Restaurant tab's one Saved Dishes switch. With the feature off there are no hearts
    // anywhere and no header link, so a direct visit gets the neutral state rather than a
    // list nothing can add to or remove from. Nothing is destroyed — the entries stay in the
    // customer's browser and reappear the moment the switch goes back on.
    $wishlistEnabled = filter_var($settings['wishlist_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

    // ONE variable for the @json below, not an inline array literal. Blade's @json splits its
    // expression on top-level commas to find its optional $options/$depth, so `@json([...])`
    // compiles to a multi-argument json_encode — wrong data when the element count fits, a 500
    // when it does not. Every driver in this theme builds the array first for this reason; this
    // page had kept the broken shape. No literal currency symbol either — the store always sets
    // one, and an empty fallback shows a bare number rather than the wrong currency's sign.
    $currencyConfig = [
        'symbol'   => $appSettings['currency_symbol'] ?? '',
        'position' => $appSettings['currency_position'] ?? 'prefix',
    ];
@endphp
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @elseif(!$wishlistEnabled)
        <div class="saffron-section">
            <h1 class="saffron-section-title mb-3">{{ __('Saved dishes') }}</h1>
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('Saved dishes are switched off for this shop.') }}</p>
            </div>
            <div class="mt-4">
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--accent">{{ __('Browse the menu') }}</a>
            </div>
        </div>
    @else
        <div id="saffron-wishlist" class="saffron-section">
            <h1 class="saffron-section-title mb-3">{{ __('Saved dishes') }}</h1>

            <div v-if="lines.length === 0" v-cloak>
                <div class="saffron-menu__empty">
                    <p class="mb-0">{{ __('You have not saved any dishes yet.') }}</p>
                </div>
                <div class="mt-4">
                    <a href="{{ url('/') }}" class="saffron-btn saffron-btn--accent">{{ __('Browse the menu') }}</a>
                </div>
            </div>

            <ul v-else v-cloak class="saffron-wishlist">
                <li v-for="line in lines" :key="line.id" class="saffron-wishlist__item">
                    <component :is="line.url ? 'a' : 'div'" :href="line.url" class="saffron-wishlist__media">
                        <img v-if="line.image" :src="line.image" :alt="line.title"
                             class="saffron-wishlist__img" loading="lazy" decoding="async">
                        <span v-else class="saffron-wishlist__placeholder" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="28" height="28" stroke="currentColor" stroke-width="1.4"
                                 fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12h18"></path>
                                <path d="M5 12a7 7 0 0 1 14 0"></path>
                                <path d="M4 16h16"></path>
                                <path d="M7 20h10"></path>
                            </svg>
                        </span>
                    </component>

                    <div class="saffron-wishlist__body">
                        <h2 class="saffron-wishlist__title">
                            <a v-if="line.url" :href="line.url">@{{ line.title }}</a>
                            <span v-else>@{{ line.title }}</span>
                        </h2>
                        <p class="saffron-wishlist__price mb-0">@{{ money(line.price) }}</p>
                    </div>

                    <div class="saffron-wishlist__actions">
                        {{-- The primary action is the dish sheet, not Add to Cart. A dish may
                             ask a compulsory question ("Choose your protein") and adding it
                             from here would send the kitchen an order with no answers — the
                             same defect the dish card's quick-add gate exists to prevent
                             (audit A1). The sheet is where those questions get answered. --}}
                        <a v-if="line.url" :href="line.url" class="saffron-btn saffron-btn--accent saffron-btn--sm">
                            {{ __('View dish') }}
                        </a>
                        <button type="button" class="saffron-wishlist__remove" @click="remove(line.id)">
                            {{ __('Remove') }}
                        </button>
                    </div>
                </li>
            </ul>
        </div>

        <script>
        (function () {
            const { createApp, computed } = Vue;

            const currency = @json($currencyConfig);

            createApp({
                setup() {
                    // Bound straight to the shared reactive store rather than copied into a
                    // local ref: removing a dish here must also unfill its heart on any card
                    // still on screen, and a second copy of the list is how those two drift.
                    const lines = computed(() => window.OvyntStore ? window.OvyntStore.wishList : []);

                    const money = (amount) => {
                        const n = Number(amount || 0).toFixed(2);
                        return currency.position === 'suffix' ? n + currency.symbol : currency.symbol + n;
                    };

                    const remove = (id) => {
                        const store = window.OvyntStore;
                        if (!store) return;

                        const at = store.wishList.findIndex((line) => line.id === id);
                        if (at > -1) {
                            store.wishList.splice(at, 1);
                            store.saveWishlist();
                        }
                    };

                    return { lines, money, remove };
                },
            }).mount('#saffron-wishlist');
        })();
        </script>
    @endif
</div>
@endsection
