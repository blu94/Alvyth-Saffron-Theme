{{-- `data-dish-id` is what the order gate scopes on: a branch that serves only some dishes
     hides the rest, and it needs to know which card is which dish. An attribute rather than a
     class, because the id is data and a class would encode it as presentation. --}}
<article id="{{ $uid }}" data-dish-id="{{ $dish->id }}" class="saffron-dish-card {{ $layout === 'list' ? 'saffron-dish-card--list' : '' }} {{ $isAvailable ? '' : 'saffron-dish-card--unavailable' }} {{ $showWishlist ? 'saffron-dish-card--savable' : '' }}">
    @if($showWishlist)
        {{-- A sibling of the media anchor, not a child: a button inside an anchor is invalid
             markup and the click would navigate to the dish before the toggle ever ran. --}}
        <button type="button" class="saffron-dish-card__save" :class="{ 'is-saved': saved }"
                @click="toggleSave" :aria-pressed="saved ? 'true' : 'false'"
                :aria-label="saved ? unsaveLabel : saveLabel" :title="saved ? unsaveLabel : saveLabel">
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="1.8"
                 :fill="saved ? 'currentColor' : 'none'" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
            </svg>
        </button>
    @endif

    @if($showQuickView)
        {{-- A sibling of the media anchor for the same reason the save heart is one: a button
             inside an anchor is invalid markup and its click would navigate to the dish
             before the dialog ever opened. --}}
        <button type="button" class="saffron-dish-card__peek" @click="openQuick"
                :aria-label="quickViewLabel" :title="quickViewLabel">
            <svg viewBox="0 0 24 24" width="17" height="17" stroke="currentColor" stroke-width="1.9"
                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0-14 0"></path>
                <path d="m21 21l-6-6"></path>
            </svg>
        </button>
    @endif

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

    @if($showQuickView)
        {{-- Teleported to the body, and this is load-bearing rather than tidiness. The card is
             `overflow: hidden` and takes `transform: translateY(-2px)` on hover, and a
             transformed ancestor becomes the containing block for `position: fixed` — so a
             dialog left inside the card would be sized against the card and clipped by it,
             exactly while the pointer rests on the card, which is the only way this opens. --}}
        <Teleport to="body">
            <div class="saffron-peek" v-if="quickOpen" @click.self="closeQuick"
                 role="dialog" aria-modal="true" :aria-label="payloadTitle">
                <div class="saffron-peek__panel">
                    <button type="button" class="saffron-peek__close" @click="closeQuick"
                            :aria-label="closeQuickLabel" :title="closeQuickLabel" ref="peekClose">
                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2"
                             fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
                    </button>

                    <div class="saffron-peek__media">
                        <img v-if="images.length" :src="images[activeImage]" :alt="payloadTitle"
                             class="saffron-peek__img">
                        <div class="saffron-peek__thumbs" v-if="images.length > 1">
                            <button type="button" v-for="(src, i) in images" :key="src"
                                    class="saffron-peek__thumb" :class="{ 'is-active': i === activeImage }"
                                    @click="activeImage = i" :aria-label="thumbLabel(i)">
                                <img :src="src" alt="" loading="lazy">
                            </button>
                        </div>
                    </div>

                    <div class="saffron-peek__body">
                        <h2 class="saffron-peek__title">@{{ payloadTitle }}</h2>

                        <p class="saffron-peek__price">
                            <span v-if="quick.fromPrice" class="saffron-peek__price-from">{{ __('from') }}</span>
                            <span>@{{ quick.price }}</span>
                        </p>

                        <p class="saffron-peek__desc" v-if="quick.description">@{{ quick.description }}</p>

                        <p class="saffron-peek__tags" v-if="quick.tags.length">
                            <span class="saffron-badge" v-for="tag in quick.tags" :key="tag">@{{ tag }}</span>
                        </p>

                        <p class="saffron-peek__soldout" v-if="!quick.available">@{{ quick.soldOut }}</p>

                        <div class="saffron-peek__actions">
                            {{-- Add straight from here only when the dish asks nothing. Anything
                                 with a size or a compulsory question goes to the sheet, which is
                                 the one place those are answered (audit A1). --}}
                            <button type="button" v-if="quick.available && quick.canAdd"
                                    class="saffron-btn saffron-btn--accent saffron-btn--large"
                                    @click="addToCart" :disabled="added">
                                @{{ added ? addedLabel : addLabel }}
                            </button>
                            <a href="{{ $url }}" class="saffron-btn"
                               :class="(quick.available && quick.canAdd) ? 'saffron-btn--outline' : 'saffron-btn--accent saffron-btn--large'">
                                @{{ viewDishLabel }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    @endif
</article>

@if($showWishlist || $showQuickView || ($showAddButton && $isAvailable && $canQuickAdd))
<script>
(function () {
    const { createApp, ref, computed } = Vue;

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

            // ── Quick view (register E8) ──────────────────────────────────────
            const quickOpen  = ref(false);
            const activeImage = ref(0);
            const images = payload.images || [];
            const quick  = payload.quick || {};
            const peekClose = ref(null);

            // Where focus was before the dialog took it, so Escape or Close puts it back on
            // the control the customer actually pressed rather than at the top of the menu.
            let returnFocusTo = null;

            const onKeydown = (event) => {
                if (event.key === 'Escape') closeQuick();
            };

            const openQuick = () => {
                returnFocusTo = document.activeElement;
                activeImage.value = 0;
                quickOpen.value = true;
                // The dialog is teleported to the body, so the page behind it is what scrolls
                // if this is not locked — the same lock the dish sheet's lightbox takes.
                document.body.style.overflow = 'hidden';
                document.addEventListener('keydown', onKeydown);
                // Focus lands on Close once Vue has actually rendered the teleported markup.
                Vue.nextTick(() => { if (peekClose.value) peekClose.value.focus(); });
            };

            const closeQuick = () => {
                quickOpen.value = false;
                document.body.style.overflow = '';
                document.removeEventListener('keydown', onKeydown);
                if (returnFocusTo && returnFocusTo.focus) returnFocusTo.focus();
            };

            // Interpolated from the driver's translated string rather than concatenated here,
            // because ":n of" and "of :n" are different sentences in different languages.
            const thumbLabel = (i) => (payload.labels.thumbnail || '').replace(':n', String(i + 1));

            const addToCart = () => {
                if (!window.OvyntStore) {
                    console.error('OvyntStore is not loaded — storefront.min.js is missing from this theme.');
                    return;
                }

                // No options bag, and that is now a guarantee rather than an assumption:
                // the driver refuses quick-add to any dish with variants OR a required
                // modifier group, so a dish reaching this branch genuinely has nothing to
                // answer. If options are ever added here, build the object in a stable key
                // order — the client dedups cart lines on JSON.stringify(options) while the
                // server ksorts before hashing.
                window.OvyntStore.addToCart(payload.dish, 1, {});
                added.value = true;
                window.setTimeout(() => { added.value = false; }, 1600);
            };

            // Saved dishes. `wishList` is a Vue.reactive array on the shared store, so a
            // computed over it re-renders every card showing this dish — save from the menu
            // and the same dish's card in a Dish Grid below fills in too.
            const saved = computed(() => {
                if (!window.OvyntStore) return false;
                return window.OvyntStore.isInWishlist(payload.dish.id);
            });

            // Deliberately not `OvyntStore.toggleWishlist()`. That method copies the line by
            // name — id, title, price, image — and drops everything else, so the saved dish
            // would arrive on the Saved Dishes page with no route back to its own sheet. The
            // page is rendered from localStorage alone; the server never sees the list and
            // cannot resolve a url for it. Splicing the reactive array and calling
            // saveWishlist() is the store's own persistence path — it is exactly what Ella's
            // wishlist page does to remove a line — so nothing here bypasses the contract.
            const toggleSave = () => {
                const store = window.OvyntStore;

                if (!store) {
                    console.error('OvyntStore is not loaded — storefront.min.js is missing from this theme.');
                    return;
                }

                const at = store.wishList.findIndex((line) => line.id === payload.dish.id);

                if (at > -1) {
                    store.wishList.splice(at, 1);
                } else {
                    store.wishList.push({
                        id:    payload.dish.id,
                        title: payload.dish.title,
                        price: payload.dish.price,
                        image: payload.dish.image,
                        url:   payload.dish.url,
                    });
                }

                store.saveWishlist();
            };

            return {
                added, addLabel, addedLabel, addToCart,
                saved, toggleSave,
                saveLabel: payload.labels.save,
                unsaveLabel: payload.labels.unsave,
                quickOpen, openQuick, closeQuick, activeImage, images, quick, thumbLabel, peekClose,
                payloadTitle: payload.dish.title,
                quickViewLabel: payload.labels.quickView,
                closeQuickLabel: payload.labels.closeQuick,
                viewDishLabel: payload.labels.viewDish,
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
@endif
