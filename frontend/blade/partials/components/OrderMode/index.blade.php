{{-- Nothing to ask: one mode, and no cutlery question. Core still learns which mode the order
     is, from a hidden input read by value, and the customer is not shown a question with one
     answer. --}}
@if(count($modes) < 2 && ! $askCutlery)
    <input type="hidden" data-checkout-mode value="{{ $modes[0] ?? $offered }}">
    @if(($modes[0] ?? $offered) === 'pickup' && $pickupAddress !== '')
        <div class="saffron-order-mode saffron-order-mode--fixed">
            <p class="saffron-order-mode__fixed-label mb-0">{{ __('Collect from') }}</p>
            <p class="saffron-order-mode__address mb-0">{{ $pickupAddress }}</p>
        </div>
    @endif
@else
<div id="{{ $uid }}" class="saffron-order-mode" v-cloak v-show="visible">
    {{-- **One** [data-checkout-mode] on the page, and never on the radios.

         Core reads the FIRST such element, and if that element is a radio it takes whichever is
         `:checked` — so a third radio would post `dine_in` as the fulfilment type, which core
         does not have and would refuse with a 422. Dine-in is a kind of collection: the diner
         needs no address and pays no delivery fee, so the type core is told is `pickup`, and
         which kind of collection it is travels on `checkout_fields` instead. Reading a hidden
         input by value is the same path the single-mode branch above already uses. --}}
    <input type="hidden" data-checkout-mode :value="fulfillmentType" ref="modeInput">

    {{-- Blade, not `v-if`, for everything the server already knows. How many modes are offered,
         whether dine-in is on and whether the tables are numbered are settings, not state the
         customer changes — rendering markup only to hide it at runtime leaves controls in the
         DOM for a shop that never offered them, which is how a hidden field ends up posted. --}}
    @if(count($modes) > 1)
    <fieldset class="saffron-order-mode__group">
        <legend class="saffron-order-mode__legend">@{{ labels.heading }}</legend>

        <div class="saffron-order-mode__options">
            @if(in_array('delivery', $modes, true))
            <label class="saffron-order-mode__option" :class="{ 'is-active': mode === 'delivery' }">
                <input type="radio" name="saffron-order-mode" value="delivery"
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
            @endif

            @if(in_array('pickup', $modes, true))
            <label class="saffron-order-mode__option" :class="{ 'is-active': mode === 'pickup' }">
                <input type="radio" name="saffron-order-mode" value="pickup"
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
            @endif

            @if(in_array('dine_in', $modes, true))
            <label class="saffron-order-mode__option" :class="{ 'is-active': mode === 'dine_in' }">
                <input type="radio" name="saffron-order-mode" value="dine_in"
                       v-model="mode" class="saffron-order-mode__input">
                <span class="saffron-order-mode__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="1.7"
                         fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 3v8a2 2 0 0 0 2 2h0a2 2 0 0 0 2 -2V3"></path>
                        <path d="M9 13v8"></path>
                        <path d="M17 3c-1.5 1.5 -2 3.5 -2 6s.5 3 2 3v9"></path>
                    </svg>
                </span>
                <span class="saffron-order-mode__text">
                    <span class="saffron-order-mode__name">@{{ labels.dineIn }}</span>
                    <span class="saffron-order-mode__hint">@{{ labels.dineInHint }}</span>
                </span>
            </label>
            @endif
        </div>
    </fieldset>
    @endif

    {{-- Outside the tile group on purpose: a shop offering collection only still has a branch
         to name and an address to state, and it renders no tiles at all. --}}
    @if(in_array('pickup', $modes, true) || in_array('dine_in', $modes, true))
        {{-- One panel for both kinds of collection. **The branch question belongs to both**: a
             diner is sitting in one of the shop's rooms, so a dine-in order that named no outlet
             would reach the kitchen without the `@ Bangsar` line every collection ticket carries
             — and in a multi-branch shop that is the one thing the counter needs. Only the
             collection *address* and the ready-wording are pickup's alone; somebody already in
             the room does not need directions to it. --}}
        <div v-if="mode !== 'delivery'" class="saffron-order-mode__pickup">
            <p v-if="mode === 'pickup'" class="saffron-order-mode__ready mb-0">@{{ labels.ready }}</p>

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

                <template v-if="mode === 'pickup' && chosenOutlet && chosenOutlet.address">
                    <p class="saffron-order-mode__fixed-label mb-0">@{{ labels.collectFrom }}</p>
                    <p class="saffron-order-mode__address mb-0">@{{ chosenOutlet.address }}</p>
                </template>
            </template>

            <template v-else-if="outlets.length === 1">
                {{-- One outlet: its id still travels, so the kitchen knows which branch is
                     making the order even though the customer was never asked. --}}
                <input type="hidden" data-checkout-field="outlet_id" :value="String(outlets[0].id)">
                <template v-if="mode === 'pickup'">
                    <p class="saffron-order-mode__fixed-label mb-0">@{{ labels.collectFrom }}</p>
                    <p class="saffron-order-mode__address mb-0">@{{ outlets[0].address || pickupAddress }}</p>
                </template>
            </template>

            <template v-else-if="mode === 'pickup' && pickupAddress">
                <p class="saffron-order-mode__fixed-label mb-0">@{{ labels.collectFrom }}</p>
                <p class="saffron-order-mode__address mb-0">@{{ pickupAddress }}</p>
            </template>

            {{-- Which table. `dining` rides alongside it so the kitchen can tell a dine-in order
                 from a collection — both are `pickup` to core, and a ticket that could not tell
                 them apart would send a waiter looking for a customer who has gone home.

                 Guarded in Blade: a shop that does not offer dine-in must not have a
                 `data-checkout-field="dining"` input sitting in its DOM at all. --}}
            @if(in_array('dine_in', $modes, true))
            <template v-if="mode === 'dine_in'">
                <input type="hidden" data-checkout-field="dining" value="dine_in">

                <label class="saffron-order-mode__outlet-label" :for="uid + '-table'">@{{ labels.chooseTable }}</label>

                {{-- The table count is a setting, so only one of the two controls is ever sent
                     to the browser. Rendering both and letting `v-if` pick would leave a second
                     `table_number` field in the DOM, and core's collector keeps the last one it
                     reads. --}}
                @if($tables > 0)
                <select :id="uid + '-table'" class="saffron-order-mode__outlet"
                        v-model="table" data-checkout-field="table_number">
                    <option v-for="n in tables" :key="n" :value="String(n)">@{{ labels.tableOption.replace(':number', n) }}</option>
                </select>
                @else
                <input :id="uid + '-table'" type="text" class="saffron-order-mode__table"
                       v-model="table" data-checkout-field="table_number"
                       :placeholder="labels.tablePlaceholder" maxlength="20" autocomplete="off">
                @endif
            </template>
            @endif
        </div>
    @endif

    {{-- Cutlery. An opt-out, and never asked of a diner sitting at a laid table. An unchecked
         checkbox is skipped by core's collector, so ticking it is the only thing that posts —
         "absent" and "false" are the same answer, which is what an opt-out means. --}}
    @if($askCutlery)
    <label v-if="mode !== 'dine_in'" class="saffron-order-mode__cutlery">
        <input type="checkbox" value="1" data-checkout-field="no_cutlery"
               v-model="noCutlery" class="saffron-order-mode__cutlery-input">
        <span class="saffron-order-mode__cutlery-text">
            <span class="saffron-order-mode__cutlery-name">@{{ labels.noCutlery }}</span>
            <span class="saffron-order-mode__hint">@{{ labels.noCutleryHint }}</span>
        </span>
    </label>
    @endif
</div>

<script>
(function () {
    const { createApp, ref, computed, watch, nextTick, onMounted } = Vue;

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
            const modes   = payload.modes || ['delivery'];
            const mode    = ref(modes[0]);

            // What core is told. Dine-in is a collection as far as an address and a delivery
            // fee are concerned, and those are the only two things the type decides.
            const fulfillmentType = computed(() => mode.value === 'delivery' ? 'delivery' : 'pickup');

            // **A bound value is not a change event, and core listens for the event.**
            //
            // Core re-renders the whole checkout region — the address panel, the fee row, the
            // totals, the tax quote — from a delegated `change` listener that fires when the
            // event's target sits inside a `[data-checkout-mode]` element. While the radios
            // carried that attribute themselves a click fired it for free; now that the
            // attribute lives on one hidden input (so a third tile cannot post a type core does
            // not have), nothing fires it, because assigning a value in JavaScript never does.
            //
            // The symptom without this is quiet and looks like a theme bug: Dine in is chosen,
            // the summary keeps asking for a delivery address, and the customer fills it in.
            // Dispatched after the DOM has the new value, so the listener reads what it expects.
            const modeInput = ref(null);

            watch(fulfillmentType, () => {
                nextTick(() => {
                    modeInput.value?.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

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

            // The table. Seeded with the first one when the shop numbers them, so a select is
            // never posted empty; left blank when the customer types it, because guessing a
            // table name is worse than asking.
            const tables = payload.tables || 0;
            const table  = ref(tables > 0 ? '1' : '');

            // Cutlery is a takeaway question. Somebody who switches to dining in after ticking
            // it should not have the answer follow them to a table that is already laid — and
            // the control is hidden by then, so it could not be un-ticked.
            const noCutlery = ref(false);
            watch(mode, m => { if (m === 'dine_in') noCutlery.value = false; });

            return {
                mode, modes, fulfillmentType, modeInput,
                visible,
                labels: payload.labels,
                pickupAddress: payload.pickupAddress,
                outlets, outletId, chosenOutlet,
                tables, table,
                noCutlery,
                uid: '{{ $uid }}',
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
@endif
