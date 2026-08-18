{{-- A shop offering one mode asks nothing and states the fact in a hidden input: core still
     learns which mode the order is, and the customer is not shown a question with one answer.
     `data-checkout-mode` on a non-radio element is read by value, so this needs no script. --}}
@if($offered !== 'both')
    <input type="hidden" data-checkout-mode value="{{ $offered }}">
    @if($offered === 'pickup' && $pickupAddress !== '')
        <div class="saffron-order-mode saffron-order-mode--fixed">
            <p class="saffron-order-mode__fixed-label mb-0">{{ __('Collect from') }}</p>
            <p class="saffron-order-mode__address mb-0">{{ $pickupAddress }}</p>
        </div>
    @endif
@else
<div id="{{ $uid }}" class="saffron-order-mode" v-cloak v-show="visible">
    <fieldset class="saffron-order-mode__group">
        <legend class="saffron-order-mode__legend">@{{ labels.heading }}</legend>

        <div class="saffron-order-mode__options">
            <label class="saffron-order-mode__option" :class="{ 'is-active': mode === 'delivery' }">
                {{-- The value core reads. Radios are only collected when checked, so the two
                     inputs need no hidden field kept in step. --}}
                <input type="radio" name="saffron-order-mode" value="delivery" data-checkout-mode
                       v-model="mode" class="saffron-order-mode__input">
                <span class="saffron-order-mode__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="1.7"
                         fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 16V7a1 1 0 0 1 1-1h9v10"></path>
                        <path d="M13 9h4l3 3v4"></path>
                        <circle cx="7" cy="17" r="2"></circle>
                        <circle cx="17" cy="17" r="2"></circle>
                    </svg>
                </span>
                <span class="saffron-order-mode__text">
                    <span class="saffron-order-mode__name">@{{ labels.delivery }}</span>
                    <span class="saffron-order-mode__hint">@{{ labels.deliveryHint }}</span>
                </span>
            </label>

            <label class="saffron-order-mode__option" :class="{ 'is-active': mode === 'pickup' }">
                <input type="radio" name="saffron-order-mode" value="pickup" data-checkout-mode
                       v-model="mode" class="saffron-order-mode__input">
                <span class="saffron-order-mode__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="1.7"
                         fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 8h14l-1 12H6z"></path>
                        <path d="M9 8V6a3 3 0 0 1 6 0v2"></path>
                    </svg>
                </span>
                <span class="saffron-order-mode__text">
                    <span class="saffron-order-mode__name">@{{ labels.pickup }}</span>
                    <span class="saffron-order-mode__hint">@{{ labels.pickupHint }}</span>
                </span>
            </label>
        </div>

        <div v-if="mode === 'pickup'" class="saffron-order-mode__pickup">
            <p class="saffron-order-mode__ready mb-0">@{{ labels.ready }}</p>

            {{-- The branch picker, shown only when the shop has more than one outlet that
                 collects. With one, the block below states its address instead — a question with
                 a single answer is not a question. The chosen id rides to the server on
                 `data-checkout-field`, the bag this theme already uses for the scheduled time,
                 so it needs no core change to be persisted onto the order. --}}
            <template v-if="outlets.length > 1">
                <label class="saffron-order-mode__outlet-label" :for="uid + '-outlet'">@{{ labels.chooseOutlet }}</label>
                <select :id="uid + '-outlet'" class="saffron-order-mode__outlet"
                        v-model="outletId" data-checkout-field="outlet_id">
                    <option v-for="outlet in outlets" :key="outlet.id" :value="String(outlet.id)">@{{ outlet.title }}</option>
                </select>

                <template v-if="chosenOutlet && chosenOutlet.address">
                    <p class="saffron-order-mode__fixed-label mb-0">@{{ labels.collectFrom }}</p>
                    <p class="saffron-order-mode__address mb-0">@{{ chosenOutlet.address }}</p>
                </template>
            </template>

            <template v-else-if="outlets.length === 1">
                {{-- One outlet: its id still travels, so the kitchen knows which branch is
                     making the order even though the customer was never asked. --}}
                <input type="hidden" data-checkout-field="outlet_id" :value="String(outlets[0].id)">
                <p class="saffron-order-mode__fixed-label mb-0">@{{ labels.collectFrom }}</p>
                <p class="saffron-order-mode__address mb-0">@{{ outlets[0].address || pickupAddress }}</p>
            </template>

            <template v-else-if="pickupAddress">
                <p class="saffron-order-mode__fixed-label mb-0">@{{ labels.collectFrom }}</p>
                <p class="saffron-order-mode__address mb-0">@{{ pickupAddress }}</p>
            </template>
        </div>
    </fieldset>
</div>

<script>
(function () {
    const { createApp, ref, computed, onMounted } = Vue;

    const payload = @json($payload);

    // Seated directly above the checkout button for the same reason the schedule block is: a
    // control that changes what the order *is* has to be met before the button that places it,
    // and the summary card holding that button belongs to core's Cart section, which a theme
    // may only precede or follow. Moving the mounted element is safe — Vue binds to nodes, not
    // to their position — and if core ever drops the hook the block simply stays where the
    // server put it, which is still ahead of the button.
    const root = document.getElementById(@json($uid));

    const seat = () => {
        const button = document.querySelector('[data-co-place]');

        if (!button || !button.parentNode || !root) return !!button;

        // Above the DELIVERY ADDRESS panel, not merely above the checkout button.
        //
        // Seating it by the button alone put the address form first, so the summary asked for
        // a delivery address before asking whether the customer wanted delivery — and the
        // panel it asks in is the one this control hides. Mode has to come before the fields
        // it governs.
        //
        // Falls back to the schedule block, then the button, so the order is always: what kind
        // of order → where it goes → when → place it.
        const panel    = document.querySelector('[data-co-panel]');
        const schedule = document.getElementById('saffron-order-schedule');

        // Inserted into the anchor's OWN parent: `insertBefore` requires the reference node to
        // be a direct child, and climbing to a card with `closest()` threw NotFoundError on
        // every one of the retries below.
        const anchor = (panel && panel.parentNode)
            ? panel
            : ((schedule && schedule.parentNode === button.parentNode) ? schedule : button);

        if (root.nextElementSibling === anchor) return true;

        anchor.parentNode.insertBefore(root, anchor);

        return true;
    };

    // The script runs where the component is printed, above the cart section, so the target
    // does not exist on the first pass; core also re-renders the summary as the cart
    // validates. Re-checked for a few seconds, then left where the server put it.
    if (!seat()) {
        let tries = 0;
        const timer = setInterval(() => {
            if (seat() || ++tries > 40) clearInterval(timer);
        }, 100);
    }

    createApp({
        setup() {
            const mounted = ref(false);
            const mode    = ref('delivery');

            // Hidden until there is something to fulfil. The cart lives in the browser, so the
            // server cannot know whether it is empty at render time; asking how someone wants
            // an order they have not started is noise.
            const cartCount = computed(() => {
                if (!window.OvyntStore) return 0;
                return window.OvyntStore.cartList.reduce((n, l) => n + (l.quantity || 0), 0);
            });

            const visible = computed(() => mounted.value && cartCount.value > 0);

            onMounted(() => { mounted.value = true; });

            // The branch. Seeded with the shop's default, which the driver put first, so the
            // field is never posted empty. Kept as a string because that is what a `<select>`
            // gives back, and a number here would fail the `:value` comparison and leave the
            // control looking unselected.
            const outlets = payload.outlets || [];
            const outletId = ref(outlets.length ? String(outlets[0].id) : '');
            const chosenOutlet = computed(() => outlets.find(o => String(o.id) === outletId.value) || null);

            return {
                mode,
                visible,
                labels: payload.labels,
                pickupAddress: payload.pickupAddress,
                outlets, outletId, chosenOutlet,
                uid: '{{ $uid }}',
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
@endif
