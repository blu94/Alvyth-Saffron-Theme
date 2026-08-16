{{-- The visibility gate sits on an INNER element, never on the mount container: Vue compiles
     the container's innerHTML as the template, so a binding on the container itself is a raw
     attribute nothing ever evaluates — a `:hidden` there kept this block invisible for good.
     `v-cloak` (styled display:none in the component SCSS) hides the card until Vue takes
     over, which also keeps it out of the page for the no-JS visitor, who could not post the
     field through the cart's JS checkout anyway; after mount, `v-show` shows it only while
     the browser-held cart has lines — there is no order to schedule on an empty cart. --}}
<div id="saffron-order-schedule">
<div class="saffron-schedule" v-cloak v-show="visible">
    <h2 class="saffron-schedule__title">{{ __('When do you want it') }}</h2>

    <div class="saffron-schedule__modes" role="radiogroup" aria-label="{{ __('When do you want it') }}">
        <label class="saffron-schedule__mode" :class="{ 'is-active': mode === 'asap' }">
            <input type="radio" name="saffron-schedule-mode" value="asap" v-model="mode">
            <span>@{{ labels.asap }}</span>
        </label>
        <label class="saffron-schedule__mode" :class="{ 'is-active': mode === 'later' }">
            <input type="radio" name="saffron-schedule-mode" value="later" v-model="mode">
            <span>@{{ labels.schedule }}</span>
        </label>
    </div>

    <div class="saffron-schedule__slots" v-if="mode === 'later'">
        <label class="saffron-schedule__field">
            <span class="saffron-schedule__label">@{{ labels.day }}</span>
            <select v-model="day" class="saffron-schedule__select">
                <option v-for="d in days" :key="d.date" :value="d.date">@{{ d.label }}</option>
            </select>
        </label>

        <label class="saffron-schedule__field">
            <span class="saffron-schedule__label">@{{ labels.time }}</span>
            <select v-model="time" class="saffron-schedule__select">
                <option v-for="t in slotsForDay" :key="t" :value="t">@{{ t }}</option>
            </select>
        </label>
    </div>

    {{-- The whole point of the block: an ordinary checkout field the cart section collects
         into `plugin_fields`. ASAP posts the empty string, which the kitchen reads as ASAP. --}}
    <input type="hidden" data-checkout-field="scheduled_at" :value="fieldValue">
</div>
</div>

<script>
(function () {
    const { createApp, ref, computed, watch, onMounted } = Vue;

    const root = document.getElementById('saffron-order-schedule');
    if (!root) return;

    const payload = @json($payload);

    // ── Placement ────────────────────────────────────────────────────────────────
    // This block asks the customer a question about the order, so it has to be met
    // BEFORE the button that places it. The button lives in core's Cart section
    // summary card, and a theme can only render before or after that whole section —
    // so the block ships above the cart (its floor, already ahead of the button) and
    // then moves itself directly above the button once the button is in the DOM.
    //
    // Shipped below the cart first, which put the picker ~200px past Proceed to
    // Checkout: a customer who did not scroll ordered ASAP never knowing scheduling
    // existed. Moving the mounted element is safe — Vue binds to the nodes, not to
    // their position — and if core's markup ever drops `data-co-place` the block
    // simply stays where the server put it, which is still before the button.
    const seat = () => {
        const button = document.querySelector('[data-co-place]');

        if (!button || !button.parentNode || button.previousElementSibling === root) {
            return !!button;
        }

        button.parentNode.insertBefore(root, button);
        const card = root.querySelector('.saffron-schedule');
        if (card) card.classList.add('saffron-schedule--inline');

        return true;
    };

    // The script runs where the component is printed, which is above the cart section,
    // so the target does not exist yet on the first pass. Core also re-renders the
    // summary as the cart validates, so the seat is re-checked for a few seconds.
    if (!seat()) {
        let tries = 0;
        const timer = setInterval(() => {
            if (seat() || ++tries > 40) clearInterval(timer);
        }, 100);
    }

    createApp({
        setup() {
            const days   = payload.days;
            const labels = payload.labels;

            const mode    = ref('asap');
            const day     = ref(days[0] ? days[0].date : '');
            const time    = ref('');
            const mounted = ref(false);

            const slotsForDay = computed(() => {
                const found = days.find(d => d.date === day.value);
                return found ? found.slots : [];
            });

            // Changing the day must never leave a time the new day does not offer.
            watch(day, () => { time.value = slotsForDay.value[0] || ''; });
            watch(mode, () => {
                if (mode.value === 'later' && !time.value) time.value = slotsForDay.value[0] || '';
            });

            const fieldValue = computed(() =>
                mode.value === 'later' && day.value && time.value
                    ? day.value + ' ' + time.value
                    : ''
            );

            // `OvyntStore` is a shared Vue.reactive object, so once it exists its cart
            // mutations re-run this computed. Until it exists nothing is tracked, so a
            // short poll bumps `storeTick` to force one re-read — the same script-order
            // race the checkout-success page papers over the same way.
            const storeTick = ref(0);
            const cartCount = computed(() => {
                void storeTick.value;
                if (!window.OvyntStore) return 0;
                return window.OvyntStore.cartList.length;
            });

            const visible = computed(() => mounted.value && cartCount.value > 0);

            onMounted(() => {
                mounted.value = true;

                let tries = 0;
                const timer = setInterval(() => {
                    storeTick.value++;
                    if (window.OvyntStore || ++tries > 30) clearInterval(timer);
                }, 100);
            });

            return { days, labels, mode, day, time, slotsForDay, fieldValue, visible };
        },
    }).mount('#saffron-order-schedule');
})();
</script>
