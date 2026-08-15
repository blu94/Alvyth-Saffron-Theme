@if(!$dish)
    @if(config('app.debug'))
        <section class="saffron-container saffron-section">
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('Dish Sheet resolved no dish. Place this section on a Product page, or set a Dish ID in its settings.') }}</p>
            </div>
        </section>
    @endif
@else
<section id="{{ $uid }}" class="saffron-dish-sheet">
    <div class="saffron-container">
        <div class="row g-4 g-lg-5">
            <div class="col-12 col-lg-6">
                <div class="saffron-dish-sheet__media">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $heading }}" class="saffron-dish-sheet__img">
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
            </div>

            <div class="col-12 col-lg-6">
                <h1 class="saffron-dish-sheet__title">{{ $heading }}</h1>

                @if($description !== '')
                    <p class="saffron-dish-sheet__desc">{{ $description }}</p>
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

                @if($hasUnpricedModifiers && config('app.debug'))
                    <p class="saffron-dish-sheet__warning">
                        {{ __('One or more modifiers on this dish carry a price. Nothing in core applies a modifier surcharge, so the server will re-price this line without it. Decide RESTAURANT-THEME-SPEC.md §17.3 before charging for add-ons.') }}
                    </p>
                @endif
                @endif
            </div>
        </div>
    </div>
</section>

@if($isAvailable)
<script>
(function () {
    const { createApp, ref, reactive, computed } = Vue;

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

            return {
                variants, groups, selectedVariant, quantity, notes, added, errors, formError,
                addedLabel, maxQty, money, runningTotal, upToLabel, isChosen, isBlocked,
                pickSingle, toggleMulti, setQty, addToCart,
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
@endif
@endif
