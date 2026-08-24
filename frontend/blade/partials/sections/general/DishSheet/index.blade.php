@if(!$dish)
    @if(config('app.debug'))
        <section class="saffron-container saffron-section">
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('Dish Sheet resolved no dish. Place this section on a Product page, or set a Dish ID in its settings.') }}</p>
            </div>
        </section>
    @endif
@else
{{-- `id` is the operator's Custom CSS ID when they set one; `data-dish-sheet` is what the Vue
     app mounts on. They are deliberately two attributes: an id an operator can retype at any
     time must not be the selector the ordering form depends on. --}}
<section id="{{ $cssId }}" data-dish-sheet="{{ $uid }}"
         class="saffron-dish-sheet saffron-dish-sheet--{{ $layout }} {{ $cssClass }}">
    <div class="{{ $containerClass }}">
        <div class="row g-4 g-lg-5">
            @if($hasSidebar && $sidebarSide === 'left')
                {{-- `v-pre` so Vue leaves the rail alone: it is server-rendered, holds no
                     directives, and its dish titles are content that must never be read as
                     interpolation. --}}
                <div class="col-12 col-lg-3" v-pre>
                    <x-theme.component name="DishSidebar" :data="array_merge($sidebarProps, ['current_id' => $dishId])" />
                </div>
            @endif

            <div class="{{ $mainColClass }}">
            <div class="row g-4 g-lg-5">
            <div class="{{ $mediaColClass }}">
                {{-- Photo and thumbnails stick together. The media alone used to carry
                     `position: sticky`, so once the thumbnails were added below it they
                     scrolled up underneath the pinned image and collided with it. One sticky
                     wrapper round both is the fix; a z-index on the strip would only have
                     hidden the overlap. --}}
                <div class="saffron-dish-sheet__gallery">

                @if($layout === 'grid' && count($images) > 0)
                    {{-- Layout 03 — Lookbook. Every photograph at once, each its own lightbox
                         trigger, and no thumbnail strip: a grid that shows all of them has
                         nothing left for a strip to select. --}}
                    <div class="saffron-dish-sheet__grid">
                        @foreach($images as $index => $src)
                            <button type="button" class="saffron-dish-sheet__grid-item"
                                    @click="openLightbox({{ $index }})"
                                    :aria-label="zoomLabel" :title="zoomLabel">
                                <img src="{{ $src }}" alt="{{ $heading }}"
                                     loading="{{ $loop->first ? 'eager' : 'lazy' }}">
                            </button>
                        @endforeach
                    </div>

                @elseif($layout === 'slider' && count($images) > 0)
                    {{-- Layout 04 — one photograph at a time on a track the arrows and dots
                         move. It shares `activeImage` with the lightbox, so whichever slide is
                         showing is the one that opens full size. --}}
                    <div class="saffron-dish-sheet__slider">
                        <div class="saffron-dish-sheet__slider-viewport">
                            <div class="saffron-dish-sheet__slider-track"
                                 :style="{ transform: 'translateX(-' + (activeImage * 100) + '%)' }">
                                <button type="button" v-for="(src, i) in images" :key="src"
                                        class="saffron-dish-sheet__slide" @click="openLightbox(i)"
                                        :aria-hidden="i === activeImage ? 'false' : 'true'"
                                        :tabindex="i === activeImage ? 0 : -1"
                                        :aria-label="zoomLabel" :title="zoomLabel">
                                    <img :src="src" alt="{{ $heading }}">
                                </button>
                            </div>
                        </div>

                        <button type="button" class="saffron-dish-sheet__slider-nav saffron-dish-sheet__slider-nav--prev"
                                v-if="images.length > 1" @click="stepImage(-1)" :aria-label="labels.prevPhoto">
                            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2"
                                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M15 18l-6-6 6-6"></path>
                            </svg>
                        </button>

                        <button type="button" class="saffron-dish-sheet__slider-nav saffron-dish-sheet__slider-nav--next"
                                v-if="images.length > 1" @click="stepImage(1)" :aria-label="labels.nextPhoto">
                            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2"
                                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 18l6-6-6-6"></path>
                            </svg>
                        </button>

                        <div class="saffron-dish-sheet__dots" v-if="images.length > 1">
                            <button type="button" v-for="(src, i) in images" :key="'dot-' + src"
                                    class="saffron-dish-sheet__dot" :class="{ 'is-active': i === activeImage }"
                                    @click="activeImage = i"
                                    :aria-current="i === activeImage ? 'true' : 'false'"
                                    :aria-label="thumbLabel.replace(':n', i + 1)"></button>
                        </div>
                    </div>

                @else
                <div class="saffron-dish-sheet__media">
                    @if($imageUrl)
                        <button type="button" class="saffron-dish-sheet__zoom" @click="openLightbox(activeImage)"
                                @mousemove="onMagnify" @mouseleave="resetMagnify"
                                :aria-label="zoomLabel" :title="zoomLabel">
                            <img :src="images[activeImage]" alt="{{ $heading }}"
                                 class="saffron-dish-sheet__img"
                                 :class="{ 'is-zooming': isZooming }"
                                 :style="{ '--magnifier-x': magnifierX + '%', '--magnifier-y': magnifierY + '%' }">
                        </button>
                    @else
                        <span class="saffron-dish-card__placeholder">
                            <svg viewBox="0 0 24 24" width="48" height="48" stroke="currentColor" stroke-width="1.3"
                                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 12h18"></path>
                                <path d="M5 12a7 7 0 0 1 14 0"></path>
                                <path d="M4 16h16"></path>
                                <path d="M7 20h10"></path>
                            </svg>
                        </span>
                    @endif
                </div>

                {{-- Thumbnails. Rendered only when there is more than one photograph — a strip
                     of one is a control that cannot do anything. Layouts 06 and 07 stand the
                     same strip on its side beside the photo; the CSS does that, not a second
                     copy of the markup. --}}
                <div class="saffron-dish-sheet__thumbs" v-if="images.length > 1">
                    <button type="button" v-for="(src, i) in images" :key="src"
                            class="saffron-dish-sheet__thumb" :class="{ 'is-active': i === activeImage }"
                            @click="activeImage = i"
                            :aria-current="i === activeImage ? 'true' : 'false'"
                            :aria-label="thumbLabel.replace(':n', i + 1)">
                        <img :src="src" alt="" loading="lazy">
                    </button>
                </div>
                @endif

                </div>
            </div>

            <div class="{{ $infoColClass }}">
                <div class="saffron-dish-sheet__head">
                    <h1 class="saffron-dish-sheet__title">{{ $heading }}</h1>
                    @if($showWishlist)
                        <button type="button" class="saffron-dish-sheet__save" :class="{ 'is-saved': saved }"
                                @click="toggleSave" :aria-pressed="saved ? 'true' : 'false'"
                                :aria-label="saved ? unsaveLabel : saveLabel" :title="saved ? unsaveLabel : saveLabel">
                            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="1.8"
                                 :fill="saved ? 'currentColor' : 'none'" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                            </svg>
                        </button>
                    @endif

                    <div class="saffron-share" ref="shareRoot">
                        <button type="button" class="saffron-share__trigger" ref="shareTrigger" @click="onShare"
                                :aria-expanded="shareOpen ? 'true' : 'false'"
                                :aria-label="labels.shareDish" :title="labels.shareDish">
                            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="1.8"
                                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.6" y1="13.5" x2="15.4" y2="17.5"></line>
                                <line x1="15.4" y1="6.5" x2="8.6" y2="10.5"></line>
                            </svg>
                        </button>

                        {{-- Deliberately NOT `data-share-popup`, though the shipped bundle
                             ships a handler for it: that handler binds once at page load, and
                             this panel is created by Vue when the button is clicked, so it
                             would never be bound. The attribute would have looked correct and
                             the links would have navigated the customer away from the shop —
                             measured, not assumed. `openShare` does the same job for markup
                             that appears later. --}}
                        <div class="saffron-share__panel" v-if="shareOpen" v-cloak>
                            <div class="saffron-share__grid">
                                @foreach($shareNetworks as $network)
                                    <a href="{{ $network['url'] }}" @click="openShare($event)"
                                       class="saffron-share__net saffron-share__net--{{ $network['key'] }}"
                                       target="_blank" rel="noopener" title="{{ $network['label'] }}"
                                       aria-label="{{ __('Share on :network', ['network' => $network['label']]) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">{!! $network['icon'] !!}</svg>
                                    </a>
                                @endforeach

                                <button type="button" class="saffron-share__net saffron-share__net--copy"
                                        :class="{ 'is-copied': copied }" @click="copyLink"
                                        :title="copied ? labels.copied : labels.copyLink"
                                        :aria-label="copied ? labels.copied : labels.copyLink">
                                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2"
                                         fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                    </svg>
                                    <span class="saffron-share__toast" v-if="copied" v-cloak>@{{ labels.copied }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                @if($showReviews)
                    {{-- Its own line under the head, not inside it. The head is a flex row
                         holding the title and the save/share controls, so putting the rating
                         there made it a third item competing for the same width — it squeezed
                         into a column and wrapped one word per line. Server-rendered, and shown
                         even at zero as empty stars reading "No reviews yet", because a shop
                         with no reviews should read as an invitation rather than as a shop with
                         no review feature. --}}
                    <a href="#dish-reviews-{{ $payload['dish']['id'] }}" class="saffron-dish-sheet__rating">
                        <span class="saffron-dish-sheet__rating-stars" aria-hidden="true">
                            @for($i = 1; $i <= 5; $i++)
                                <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="1.6"
                                     fill="{{ $reviewSummary['average'] !== null && $i <= round($reviewSummary['average']) ? 'currentColor' : 'none' }}">
                                    <path d="M8.243 7.34l-6.38.925-.113.023a1 1 0 0 0-.44 1.684l4.622 4.499-1.09 6.355-.013.11a1 1 0 0 0 1.464.944l5.706-3 5.693 3 .1.046a1 1 0 0 0 1.352-1.1l-1.091-6.355 4.624-4.5.078-.085a1 1 0 0 0-.633-1.62l-6.38-.926-2.852-5.78a1 1 0 0 0-1.794 0z"></path>
                                </svg>
                            @endfor
                        </span>
                        @if($reviewSummary['count'] > 0)
                            <span>{{ $reviewSummary['average'] }} {{ __('out of 5') }}</span>
                            <span class="saffron-dish-sheet__rating-count">{{ trans_choice('{1} :count review|[2,*] :count reviews', $reviewSummary['count'], ['count' => $reviewSummary['count']]) }}</span>
                        @else
                            <span class="saffron-dish-sheet__rating-count">{{ __('No reviews yet') }}</span>
                        @endif
                    </a>
                @endif

                @if($description !== '')
                    <p class="saffron-dish-sheet__desc">{{ $description }}</p>
                @endif

                @if(!empty($categoryLinks) || !empty($tags))
                    <div class="saffron-dish-sheet__meta">
                        @if(!empty($categoryLinks))
                            <p class="saffron-dish-sheet__meta-row">
                                <span class="saffron-dish-sheet__meta-label">{{ __('Categories:') }}</span>
                                @foreach($categoryLinks as $category)
                                    <a href="{{ $category['url'] }}" class="saffron-badge saffron-badge--link">{{ $category['title'] }}</a>
                                @endforeach
                            </p>
                        @endif

                        @if(!empty($tags))
                            <p class="saffron-dish-sheet__meta-row">
                                <span class="saffron-dish-sheet__meta-label">{{ __('Tags:') }}</span>
                                @foreach($tags as $tag)
                                    <span class="saffron-badge">{{ $tag }}</span>
                                @endforeach
                            </p>
                        @endif
                    </div>
                @endif

                @unless($isAvailable)
                    <p class="saffron-dish-sheet__soldout">{{ $soldOutLabel }}</p>
                @endunless

                @if($isAvailable)
                <form class="saffron-dish-sheet__form" @submit.prevent="addToCart">
                    @if(count($variants) > 1)
                        <fieldset class="saffron-dish-sheet__group">
                            <legend class="saffron-dish-sheet__legend">
                                {{ __('Size') }}
                                <span class="saffron-dish-sheet__req">{{ __('Required') }}</span>
                            </legend>
                            <div class="saffron-dish-sheet__options">
                                {{-- A sold-out size stays visible but cannot be picked — the driver
                                     computes v.available for exactly this, and rendering it live
                                     let a customer choose the Large that ran out and only find out
                                     at cart validation. --}}
                                <label v-for="v in variants" :key="v.id" class="saffron-choice"
                                       :class="{ 'is-selected': selectedVariant === v.id, 'is-disabled': !v.available }">
                                    <input type="radio" name="{{ $uid }}-size" :value="v.id" v-model="selectedVariant"
                                           :disabled="!v.available">
                                    <span class="saffron-choice__label">@{{ v.label }}</span>
                                    <span class="saffron-choice__price">
                                        <template v-if="v.available">@{{ money(v.price) }}</template>
                                        <template v-else>{{ $soldOutLabel }}</template>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    @endif

                    <fieldset v-for="group in groups" :key="group.id" class="saffron-dish-sheet__group">
                        <legend class="saffron-dish-sheet__legend">
                            @{{ group.title }}
                            <span class="saffron-dish-sheet__req" v-if="group.required">{{ __('Required') }}</span>
                            <span class="saffron-dish-sheet__hint" v-else-if="group.max_select">
                                @{{ upToLabel(group.max_select) }}
                            </span>
                            <span class="saffron-dish-sheet__hint" v-else>{{ __('Optional') }}</span>
                        </legend>

                        <p class="saffron-dish-sheet__group-desc" v-if="group.description">@{{ group.description }}</p>

                        <div class="saffron-dish-sheet__options">
                            <label v-for="m in group.modifiers" :key="m.id" class="saffron-choice"
                                   :class="{ 'is-selected': isChosen(group, m), 'is-disabled': isBlocked(group, m) }">
                                <input v-if="group.selection === 'single'" type="radio"
                                       :name="'{{ $uid }}-g' + group.id" :value="m.id"
                                       :checked="isChosen(group, m)" @change="pickSingle(group, m)">
                                <input v-else type="checkbox" :value="m.id"
                                       :checked="isChosen(group, m)" :disabled="isBlocked(group, m)"
                                       @change="toggleMulti(group, m)">
                                <span class="saffron-choice__label">@{{ m.label }}</span>
                                <span class="saffron-choice__price" v-if="m.price_delta">+@{{ money(m.price_delta) }}</span>
                            </label>
                        </div>

                        <p class="saffron-dish-sheet__error" v-if="errors[group.id]">@{{ errors[group.id] }}</p>
                    </fieldset>

                    @if($showNotes)
                        <div class="saffron-dish-sheet__group">
                            <label class="saffron-dish-sheet__legend" :for="'{{ $uid }}-notes'">
                                {{ $notesLabel }}
                                <span class="saffron-dish-sheet__hint">@{{ notes.length }}/{{ $notesMax }}</span>
                            </label>
                            <textarea :id="'{{ $uid }}-notes'" v-model="notes" rows="2"
                                      maxlength="{{ $notesMax }}" class="saffron-dish-sheet__notes"
                                      placeholder="{{ __('e.g. No onions, extra spicy') }}"></textarea>
                        </div>
                    @endif

                    <div class="saffron-dish-sheet__foot">
                        <div class="saffron-qty" role="group" aria-label="{{ __('Quantity') }}">
                            <button type="button" class="saffron-qty__btn" @click="setQty(quantity - 1)"
                                    :disabled="quantity <= 1" aria-label="{{ __('Decrease quantity') }}">&minus;</button>
                            <span class="saffron-qty__value" aria-live="polite">@{{ quantity }}</span>
                            <button type="button" class="saffron-qty__btn" @click="setQty(quantity + 1)"
                                    :disabled="quantity >= maxQty" aria-label="{{ __('Increase quantity') }}">+</button>
                        </div>

                        <button type="submit" class="saffron-btn saffron-btn--accent saffron-btn--large"
                                :disabled="added">
                            <span v-if="!added">{{ __('Add to order') }} &middot; @{{ money(runningTotal) }}</span>
                            <span v-else>@{{ addedLabel }}</span>
                        </button>
                    </div>

                    <p class="saffron-dish-sheet__error" v-if="formError">@{{ formError }}</p>
                </form>

                @endif
            </div>
            </div>
            </div>

            @if($hasSidebar && $sidebarSide === 'right')
                <div class="col-12 col-lg-3" v-pre>
                    <x-theme.component name="DishSidebar" :data="array_merge($sidebarProps, ['current_id' => $dishId])" />
                </div>
            @endif
        </div>
    </div>

    {{-- Lightbox (register E2). Inside the section because that is the mounted element — a
         panel outside it would be inert markup, the same trap the share popup documents.
         Closes on the backdrop, on Escape, and on its own button; arrow keys move between
         photographs while it is open. --}}
    <div class="saffron-lightbox" v-if="lightboxOpen" @click.self="closeLightbox"
         role="dialog" aria-modal="true" :aria-label="labels.closePhoto">
        <button type="button" class="saffron-lightbox__close" @click="closeLightbox"
                :aria-label="labels.closePhoto" ref="lightboxClose">
            <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="2"
                 fill="none" stroke-linecap="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"></path>
            </svg>
        </button>

        <button type="button" class="saffron-lightbox__nav saffron-lightbox__nav--prev"
                v-if="images.length > 1" @click="stepImage(-1)" :aria-label="labels.prevPhoto">
            <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2"
                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 18l-6-6 6-6"></path>
            </svg>
        </button>

        <img :src="images[activeImage]" alt="{{ $heading }}" class="saffron-lightbox__img">

        <button type="button" class="saffron-lightbox__nav saffron-lightbox__nav--next"
                v-if="images.length > 1" @click="stepImage(1)" :aria-label="labels.nextPhoto">
            <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2"
                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 18l6-6-6-6"></path>
            </svg>
        </button>

        <p class="saffron-lightbox__count" v-if="images.length > 1">@{{ activeImage + 1 }} / @{{ images.length }}</p>
    </div>
</section>

{{-- Reviews sit below the sheet, outside its Vue app — they have their own, because the
     ordering form and a review list share no state and mounting one app over both would make
     a failure in either take out the other. Ella places them in the same position. --}}
<x-theme.component name="DishReviews" :data="['dish' => $dish]" />

{{-- Also mounted for a sold-out dish when Saved Dishes is on: the ordering form is not
     rendered then, but the heart is, and an unmounted app would leave its :class and @click
     as inert attributes. Saving a dish you cannot order today is the point of saving it. --}}
@if($isAvailable || $showWishlist)
<script>
(function () {
    const { createApp, ref, reactive, computed, onMounted, onUnmounted } = Vue;

    const payload = @json($payload);

    createApp({
        setup() {
            const variants = ref(payload.variants);
            const groups   = ref(payload.groups);
            const maxQty   = payload.maxQty;

            // Preselect the first size that can actually be bought — preselecting a sold-out
            // one would greet the customer with a disabled default.
            const firstAvailable  = variants.value.find(v => v.available);
            const selectedVariant = ref(firstAvailable ? firstAvailable.id
                : (variants.value.length ? variants.value[0].id : null));
            const quantity        = ref(1);
            const notes           = ref('');
            const added           = ref(false);
            const formError       = ref('');
            const errors          = reactive({});
            const addedLabel      = payload.labels.added;

            // chosen[groupId] = [modifierId, …]. Pre-seeded from is_default so a group the
            // shop marked with a default arrives answered.
            const chosen = reactive({});
            groups.value.forEach(g => {
                const defaults = g.modifiers.filter(m => m.is_default).map(m => m.id);
                chosen[g.id] = g.selection === 'single' ? defaults.slice(0, 1) : defaults;
            });

            const money = (amount) => {
                const n = Number(amount || 0).toFixed(2);
                return payload.currency.position === 'suffix'
                    ? n + payload.currency.symbol
                    : payload.currency.symbol + n;
            };

            const basePrice = computed(() => {
                if (!selectedVariant.value) return payload.dish.price;
                const v = variants.value.find(x => x.id === selectedVariant.value);
                return v ? v.price : payload.dish.price;
            });

            // Shown to the customer only. The server re-prices every line on
            // POST /storefront/cart/validate and ignores anything sent from here, so this
            // total is a preview — and it deliberately includes modifier deltas that core
            // does not yet charge, which is why the debug warning above exists.
            const runningTotal = computed(() => {
                let unit = basePrice.value;
                groups.value.forEach(g => {
                    (chosen[g.id] || []).forEach(id => {
                        const m = g.modifiers.find(x => x.id === id);
                        if (m) unit += m.price_delta;
                    });
                });
                return Math.max(0, unit) * quantity.value;
            });

            // The driver ships this translated with a :n placeholder; interpolating here
            // rather than concatenating keeps the word order right in every locale — the
            // template used to hardcode English 'Choose up to N'.
            const upToLabel = (n) => payload.labels.upTo.replace(':n', String(n));

            const isChosen  = (group, m) => (chosen[group.id] || []).includes(m.id);
            const isBlocked = (group, m) => {
                if (group.selection === 'single' || !group.max_select) return false;
                const picked = chosen[group.id] || [];
                return picked.length >= group.max_select && !picked.includes(m.id);
            };

            const pickSingle = (group, m) => {
                chosen[group.id] = [m.id];
                delete errors[group.id];
            };

            const toggleMulti = (group, m) => {
                const picked = chosen[group.id] || [];
                const at = picked.indexOf(m.id);
                if (at > -1) {
                    picked.splice(at, 1);
                } else {
                    if (group.max_select && picked.length >= group.max_select) return;
                    picked.push(m.id);
                }
                chosen[group.id] = picked;
                delete errors[group.id];
            };

            const setQty = (next) => {
                quantity.value = Math.min(maxQty, Math.max(1, next));
            };

            const validate = () => {
                let ok = true;
                Object.keys(errors).forEach(k => delete errors[k]);
                groups.value.forEach(g => {
                    const n = (chosen[g.id] || []).length;
                    if (g.min_select && n < g.min_select) {
                        errors[g.id] = g.min_select === 1
                            ? payload.labels.chooseOne
                            : payload.labels.chooseAtLeast.replace(':n', g.min_select);
                        ok = false;
                    }
                });
                formError.value = ok ? '' : payload.labels.answerHighlighted;
                return ok;
            };

            /**
             * Build the flat options bag.
             *
             * KEY ORDER MATTERS. The client dedups cart lines on JSON.stringify(options),
             * which is key-order sensitive, while the server ksorts before hashing. Insert in
             * one deterministic order — size, then groups in their rendered order, then notes
             * — and the two agree. Construct it differently in two places and the browser
             * shows two lines where the server sees one.
             */
            const buildOptions = () => {
                const options = {};

                // Whenever a size is selected — including a dish with exactly ONE size. The
                // old `length > 1` guard dropped the Size from single-variant tickets, and
                // with it the kitchen's answer to which portion was ordered.
                if (selectedVariant.value) {
                    const v = variants.value.find(x => x.id === selectedVariant.value);
                    if (v) options[payload.labels.sizeKey] = String(v.label);
                }

                groups.value.forEach(g => {
                    const labels = (chosen[g.id] || [])
                        .map(id => (g.modifiers.find(m => m.id === id) || {}).label)
                        .filter(Boolean);
                    // Empty values are filtered by validateCart anyway; omitting them here
                    // keeps the client's hash equal to the server's.
                    if (labels.length) options[g.key] = labels.join(', ');
                });

                const note = notes.value.trim();
                if (note) options[payload.labels.notesKey] = note;

                return options;
            };

            const addToCart = () => {
                if (!validate()) return;

                if (!window.OvyntStore) {
                    formError.value = payload.labels.cartUnavailable;
                    console.error('OvyntStore is not loaded — storefront.min.js is missing from this theme.');
                    return;
                }

                // The variant is the sellable record when one is chosen — a variant IS a
                // Product with its own id, price and stock — so the cart line carries ITS
                // id, never the parent's. The old `length > 1` guard sent the parent's id
                // for a single-size dish: the sheet displayed the variant's price while the
                // server priced and stock-checked the parent, so shown ≠ charged and a
                // sold-out only-size was never refused.
                const line = {
                    id: selectedVariant.value ? selectedVariant.value : payload.dish.id,
                    title: payload.dish.title,
                    price: basePrice.value,
                    image: payload.dish.image || null,
                };

                window.OvyntStore.addToCart(line, quantity.value, buildOptions());

                added.value = true;
                formError.value = '';
                window.setTimeout(() => { added.value = false; }, 1800);
            };

            // Saved dishes. Same store, same shape and the same reason for not calling
            // OvyntStore.toggleWishlist() as the dish card: that method drops every key it
            // does not name, and the saved-dishes page needs the url to link back here.
            // The price saved is whatever the sheet is showing — the parent's, or the
            // selected size's — which makes it indicative rather than a quote. The list is
            // a reminder; the dish page prices it again on arrival.
            const saved = computed(() => {
                if (!window.OvyntStore) return false;
                return window.OvyntStore.isInWishlist(payload.dish.id);
            });

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
                        price: basePrice.value,
                        image: payload.dish.image || null,
                        url:   payload.dish.url,
                    });
                }

                store.saveWishlist();
            };

            // Sharing. The panel of networks IS the share control, on every device — the same
            // arrangement Ella uses, driven by the same Application settings. `navigator.share`
            // was tried first here once; it handed the customer the operating system's own
            // sheet, which on desktop Chrome is a Windows dialog listing Mail and Bluetooth
            // rather than the shop's chosen networks, and it made the two themes behave
            // differently on the same site.
            const shareOpen   = ref(false);
            const copied      = ref(false);
            const shareRoot   = ref(null);
            const shareTrigger = ref(null);

            // A popover a customer cannot dismiss is a popover that is stuck. Both listeners
            // are document-level because the click that should close it lands anywhere on the
            // page, and both are removed on unmount so a page with several dish sheets does
            // not accumulate them.
            const closeShareOnOutsideClick = (e) => {
                if (!shareOpen.value) return;
                if (shareRoot.value && shareRoot.value.contains(e.target)) return;
                shareOpen.value = false;
            };

            const closeShareOnEscape = (e) => {
                if (!shareOpen.value || e.key !== 'Escape') return;
                shareOpen.value = false;
                // Focus goes back where it came from, or it lands on <body> and a keyboard
                // user has to tab from the top of the page to get back to the dish.
                shareTrigger.value?.focus();
            };

            onMounted(() => {
                document.addEventListener('click', closeShareOnOutsideClick);
                document.addEventListener('keydown', closeShareOnEscape);
            });

            onUnmounted(() => {
                document.removeEventListener('click', closeShareOnOutsideClick);
                document.removeEventListener('keydown', closeShareOnEscape);
            });

            const onShare = () => {
                shareOpen.value = !shareOpen.value;
            };

            // A centred popup rather than a new tab, which is what a share window should be —
            // and the reason the panel does not use the bundle's `data-share-popup`: that
            // handler is bound once at page load and this panel does not exist yet then.
            //
            // Only for http(s). `mailto:`, `sms:` and `viber:` are handed to an app on the
            // device, and window.open()ing one of those leaves an empty popup sitting on the
            // screen while the mail client loads behind it — the bundle's handler does exactly
            // that, and it is the one behaviour here not worth copying. Those navigate normally.
            const openShare = (event) => {
                const url = event.currentTarget.getAttribute('href');

                if (/^https?:/i.test(url)) {
                    event.preventDefault();

                    const w = 620;
                    const h = 560;
                    const left = Math.max(0, (window.screen.width - w) / 2);
                    const top  = Math.max(0, (window.screen.height - h) / 2);

                    window.open(
                        url,
                        payload.labels.shareDish,
                        'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
                    );
                }

                shareOpen.value = false;
            };

            // Handled here rather than by the bundle's `data-share-copy`, which reports success
            // by toggling a class named for the other theme. A translated label on our own
            // button says the same thing without borrowing Ella's namespace.
            const copyLink = async () => {
                try {
                    await navigator.clipboard.writeText(payload.share.url);
                    copied.value = true;
                    window.setTimeout(() => { copied.value = false; }, 1800);
                } catch (e) {
                    console.error('Could not copy the link', e);
                }
            };

            // ── Gallery and lightbox (register E1, E2) ──────────────────────────────
            // The sheet showed one photograph and could not be opened; Ella's product page
            // has had a gallery and a lightbox throughout. Plain Vue, no Swiper: a strip of
            // thumbnails is not a carousel, and pulling in a slider for it would add a
            // dependency to a component whose whole job is four small buttons.
            const images       = ref(Array.isArray(payload.images) ? payload.images : []);
            const activeImage  = ref(0);
            const lightboxOpen = ref(false);

            const openLightbox = (index) => {
                if (!images.value.length) return;
                activeImage.value = index;
                lightboxOpen.value = true;
                // The page behind a full-screen overlay must not scroll under it.
                document.body.style.overflow = 'hidden';
            };

            const closeLightbox = () => {
                lightboxOpen.value = false;
                document.body.style.overflow = '';
            };

            /** Wraps at both ends, so the last photo's Next is the first rather than a dead button. */
            const stepImage = (by) => {
                if (!images.value.length) return;
                activeImage.value = (activeImage.value + by + images.value.length) % images.value.length;
            };

            // ── Hover magnifier ─────────────────────────────────────────────────────
            // Ella's, copied rather than reinvented: the image scales 2.5× and its
            // `transform-origin` tracks the pointer as a percentage, so the part under the
            // cursor is the part that grows. No second zoomed image, no canvas, no lens
            // element — one transform on the photo already on the page.
            const isZooming  = ref(false);
            const magnifierX = ref(50);
            const magnifierY = ref(50);

            const onMagnify = (e) => {
                // A coarse pointer has no hover: a tap would zoom and stick until the next
                // tap elsewhere, and the tap is meant to open the lightbox instead.
                if (!window.matchMedia('(hover: hover)').matches) return;

                const rect = e.currentTarget.getBoundingClientRect();
                isZooming.value  = true;
                magnifierX.value = ((e.clientX - rect.left) / rect.width) * 100;
                magnifierY.value = ((e.clientY - rect.top) / rect.height) * 100;
            };

            const resetMagnify = () => {
                isZooming.value = false;
                // Recentre only after the scale-out has finished, or the origin snaps back to
                // the middle while the image is still shrinking and the photo appears to jump.
                window.setTimeout(() => {
                    if (!isZooming.value) {
                        magnifierX.value = 50;
                        magnifierY.value = 50;
                    }
                }, 300);
            };

            const onLightboxKey = (e) => {
                if (!lightboxOpen.value) return;
                if (e.key === 'Escape')     closeLightbox();
                if (e.key === 'ArrowLeft')  stepImage(-1);
                if (e.key === 'ArrowRight') stepImage(1);
            };

            onMounted(() => document.addEventListener('keydown', onLightboxKey));
            onUnmounted(() => {
                document.removeEventListener('keydown', onLightboxKey);
                // Leaving with the overlay open would strand the lock on <body>.
                document.body.style.overflow = '';
            });

            return {
                images, activeImage, lightboxOpen, openLightbox, closeLightbox, stepImage,
                isZooming, magnifierX, magnifierY, onMagnify, resetMagnify,
                zoomLabel: payload.labels.zoom,
                thumbLabel: payload.labels.thumbnail,
                variants, groups, selectedVariant, quantity, notes, added, errors, formError,
                addedLabel, maxQty, money, runningTotal, upToLabel, isChosen, isBlocked,
                pickSingle, toggleMulti, setQty, addToCart,
                saved, toggleSave,
                saveLabel: payload.labels.save,
                unsaveLabel: payload.labels.unsave,
                labels: payload.labels,
                shareOpen, copied, onShare, openShare, copyLink,
                shareRoot, shareTrigger,
            };
        },
    }).mount('[data-dish-sheet="{{ $uid }}"]');
})();
</script>
@endif
@endif
