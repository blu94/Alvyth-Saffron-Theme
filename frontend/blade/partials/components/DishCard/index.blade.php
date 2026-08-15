<article id="{{ $uid }}" class="saffron-dish-card {{ $layout === 'list' ? 'saffron-dish-card--list' : '' }} {{ $isAvailable ? '' : 'saffron-dish-card--unavailable' }}">
    <a href="{{ $url }}" class="saffron-dish-card__media" aria-label="{{ $title }}" tabindex="-1">
        @if($image)
            <img src="{{ $image }}" alt="{{ $title }}" class="saffron-dish-card__img" loading="lazy" decoding="async">
        @else
            <span class="saffron-dish-card__placeholder">
                <svg viewBox="0 0 24 24" width="36" height="36" stroke="currentColor" stroke-width="1.4"
                     fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 12h18"></path>
                    <path d="M5 12a7 7 0 0 1 14 0"></path>
                    <path d="M4 16h16"></path>
                    <path d="M7 20h10"></path>
                </svg>
            </span>
        @endif

        @if(!empty($tags) || !$isAvailable)
            <span class="saffron-dish-card__badges">
                @unless($isAvailable)
                    <span class="saffron-badge saffron-badge--sold-out">{{ $soldOutLabel }}</span>
                @endunless
                @foreach($tags as $tag)
                    <span class="saffron-badge">{{ $tag }}</span>
                @endforeach
            </span>
        @endif
    </a>

    <div class="saffron-dish-card__body">
        <h3 class="saffron-dish-card__title">
            <a href="{{ $url }}">{{ $title }}</a>
        </h3>

        @if($description !== '')
            <p class="saffron-dish-card__desc saffron-clamp-2">{{ $description }}</p>
        @endif

        <div class="saffron-dish-card__foot">
            @if($showPrice)
                <p class="saffron-dish-card__price mb-0">
                    @if($isFromPrice)
                        <span class="saffron-dish-card__price-from">{{ __('from') }}</span>
                    @endif
                    <span>{{ $formattedPrice }}</span>
                    @if($formattedCompare)
                        <span class="saffron-dish-card__price-compare">{{ $formattedCompare }}</span>
                    @endif
                </p>
            @else
                <span></span>
            @endif

            @if($showAddButton)
                @if(!$isAvailable)
                    <span class="saffron-dish-card__unavailable-note">{{ $soldOutLabel }}</span>
                @elseif($canQuickAdd)
                    <button type="button" class="saffron-btn saffron-btn--accent saffron-btn--icon"
                            @click="addToCart" :disabled="added" :aria-label="added ? addedLabel : addLabel">
                        <svg v-if="!added" viewBox="0 0 24 24" width="18" height="18" stroke="currentColor"
                             stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 5v14"></path>
                            <path d="M5 12h14"></path>
                        </svg>
                        <svg v-else viewBox="0 0 24 24" width="18" height="18" stroke="currentColor"
                             stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6 9 17l-5-5"></path>
                        </svg>
                    </button>
                @else
                    <a href="{{ $url }}" class="saffron-btn saffron-btn--outline saffron-btn--sm">{{ $addButtonLabel }}</a>
                @endif
            @endif
        </div>
    </div>
</article>

@if($showAddButton && $isAvailable && $canQuickAdd)
<script>
(function () {
    const { createApp, ref } = Vue;

    // One scoped app per card, mounted on its own unique id. The payload is built by the
    // driver and handed over as JSON rather than echoed, and the price is only ever a
    // display value — the server re-prices the whole cart on
    // POST /storefront/cart/validate, so nothing here is trusted for money.
    const payload = @json($jsPayload);

    createApp({
        setup() {
            const added = ref(false);
            const addLabel = payload.labels.add;
            const addedLabel = payload.labels.added;

            const addToCart = () => {
                if (!window.OvyntStore) {
                    console.error('OvyntStore is not loaded — storefront.min.js is missing from this theme.');
                    return;
                }

                // No options bag: a dish reaching this branch has no variants, and modifier
                // groups do not exist until phase 3. When they do, build the options object
                // in a stable key order — the client dedups cart lines on
                // JSON.stringify(options) while the server ksorts before hashing, so an
                // inconsistent key order shows two lines where the server sees one.
                window.OvyntStore.addToCart(payload.dish, 1, {});
                added.value = true;
                window.setTimeout(() => { added.value = false; }, 1600);
            };

            return { added, addLabel, addedLabel, addToCart };
        },
    }).mount('#{{ $uid }}');
})();
</script>
@endif
