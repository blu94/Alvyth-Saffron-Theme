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
            <label v-if="branchOffers('delivery')" class="saffron-order-mode__option" :class="{ 'is-active': mode === 'delivery' }">
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
            <label v-if="branchOffers('pickup')" class="saffron-order-mode__option" :class="{ 'is-active': mode === 'pickup' }">
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
            <label v-if="branchOffers('dine_in')" class="saffron-order-mode__option" :class="{ 'is-active': mode === 'dine_in' }">
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
        {{-- **Pickup's panel, and only pickup's.** It holds two things — the ready-by wording
             and the collection address — and somebody already sitting in the room needs
             neither. It used to open for dine-in as well, because the table question lived
             inside it; now that the table question has moved below the branch list where it
             belongs, opening this for a diner drew a padded, sand-coloured bar with nothing
             in it. Caught in a screenshot, not by an assertion: every field was correct and
             the panel was simply empty.

             The branch question itself belongs to BOTH kinds of collection and is core's, in
             the list below — a dine-in order that named no outlet would reach the kitchen
             without the `@ Bangsar` line every ticket carries. --}}
        <div v-if="mode === 'pickup'" class="saffron-order-mode__pickup">
            <p v-if="mode === 'pickup'" class="saffron-order-mode__ready mb-0">@{{ labels.ready }}</p>

            {{-- The branch picker used to live here. It moved to CORE's cart section: each
                 branch is a pickup-type shipping method now, the customer picks one from the
                 method list, and the method's linked outlet id reaches the order server-side
                 (ShippingMethodOutlet handler → meta.checkout_fields.outlet_id), so the
                 kitchen's `@ branch` line is untouched. Rendering a second picker here would
                 ask the same question twice — and its answer would lose, because the
                 method's own fields win the server-side merge. --}}

            {{-- The collection address, one card wherever it came from. Three branches used to
                 print three copies of the same two lines; `collectAddress` resolves the source
                 (chosen branch → only branch → shop setting) so the markup exists once. It is a
                 card rather than a paragraph because this is the one line of the panel a
                 customer acts on OUTSIDE the shop — pasted into a maps app or a message to
                 whoever is driving — so it carries its own copy control instead of asking a
                 thumb to finger-select a street address. --}}
            <div v-if="mode === 'pickup' && collectAddress" class="saffron-order-mode__collect">
                {{-- A flex row rather than an absolutely-placed button: an address long enough
                     to wrap must never run underneath the control that copies it. --}}
                <div class="saffron-order-mode__collect-head">
                    <p class="saffron-order-mode__fixed-label mb-0">@{{ labels.collectFrom }}</p>
                    <button type="button" class="saffron-order-mode__copy" :class="{ 'is-copied': copied }"
                            @click="copyAddress">
                        <svg v-if="!copied" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">
                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                        {{-- aria-live, so the flip to "Copied" is announced to the screen reader
                             that cannot see the icon change. --}}
                        <span aria-live="polite">@{{ copied ? labels.copied : labels.copy }}</span>
                    </button>
                </div>
                <p class="saffron-order-mode__address mb-0">@{{ collectAddress }}</p>
            </div>

        </div>
    @endif

    {{-- `dining` is what lets the kitchen tell a dine-in order from a collection — both are
         `pickup` to core, and a ticket that could not tell them apart would send a waiter
         looking for a customer who has gone home.

         It sits at the component's own level rather than inside the pickup panel above: that
         panel is pickup-only now, so a `dining` input nested in it would never render for the
         one mode it exists to report. Core reads `[data-checkout-field]` document-wide, so
         depth is irrelevant to the contract — only presence matters.

         Guarded in Blade: a shop that does not offer dine-in must not have a
         `data-checkout-field="dining"` input sitting in its DOM at all. --}}
    @if(in_array('dine_in', $modes, true))
    <input v-if="mode === 'dine_in'" type="hidden" data-checkout-field="dining" value="dine_in">
    @endif

    {{-- WHICH TABLE — asked after the branch, because the answer depends on it.

         A table number belongs to a restaurant, so "Table 12" means nothing until the shop
         knows which dining room. This block used to render up here with the tiles, which put
         it ABOVE core's branch list — a multi-branch shop asked "which table?" and only then
         "which branch?", and the two answers reached the kitchen in an order no waiter could
         act on.

         It is teleported rather than moved: the tiles must stay above the branch list (the
         question has to precede the list its answer reveals), so the two controls genuinely
         belong at different depths of the summary. Vue owns the teleported node, so the
         `v-if` below keeps working — physically relocating a `v-if`'d node instead would see
         Vue re-insert it at its original position the next time the mode changed.

         The slot is the theme's own element, seated after `[data-co-pickup]` rather than
         inside it: core empties that box whenever it redraws the branch list. --}}
    @if(in_array('dine_in', $modes, true))
    <Teleport v-if="tableSlot" :to="'#' + tableSlot" :disabled="false">
        <div v-if="mode === 'dine_in'" class="saffron-order-mode__seating">
            {{-- HOW MANY OF YOU — asked before the table, because it decides which tables are
                 worth offering. The writer refuses a party larger than the table it names
                 (`TableReservation::write()`), so a picker that let a party of eight choose a
                 two-seater would turn that refusal up at the payment button rather than at the
                 question — the customer would have filled in a card before learning the table
                 does not fit.

                 Asked only of somebody genuinely booking: a walk-in diner has already sat down,
                 and the seats they are occupying are not a question. `askCovers` is therefore
                 gated on a *time* being chosen, not merely on dine-in.

                 Guarded in Blade as well, so a shop that does not take bookings never has a
                 `data-checkout-field="covers"` input in its DOM at all. --}}
            @if($bookings)
            <template v-if="askCovers && !branchClosedToDiners">
                <label class="saffron-order-mode__outlet-label" :for="uid + '-covers'">@{{ labels.party }}</label>
                <select :id="uid + '-covers'" class="saffron-order-mode__covers"
                        v-model.number="covers" data-checkout-field="covers">
                    <option v-for="n in maxCovers" :key="n" :value="n">
                        @{{ n === 1 ? labels.partyOne : labels.partyOption.replace(':count', n) }}
                    </option>
                </select>
            </template>
            @endif

            {{-- The branch chosen above has no dining room. Nothing that asks for a table
                 renders at all — the same honest shape as "every table is taken": there is no
                 answer to give, so the panel names what to change instead of offering a list
                 that cannot be right. The `v-else` below carries the whole question, because a
                 second `v-if` between a `v-if` and its `v-else-if` breaks the chain and hands
                 the select to the wrong branch. --}}
            <p v-if="branchClosedToDiners" class="saffron-order-mode__hint mb-0">@{{ labels.notDining }}</p>

            <template v-else>
            <label class="saffron-order-mode__outlet-label" :for="uid + '-table'">@{{ labels.chooseTable }}</label>

            {{-- **The branch's own tables, when it has any** (register O19). A table belongs to
                 one dining room, so the list follows the branch chosen just above rather than a
                 single count shared by every branch — which offered Table 15 at a room with
                 eight tables and hid Bangsar's other twelve behind KLCC's number.

                 A shop whose branches have no tables listed keeps the shop-wide count, and a
                 shop with neither keeps the free-text box. That is the upgrade path, not a
                 special case: every install starts in the fallback and leaves it a branch at a
                 time.

                 Once a time is chosen the list narrows to the tables actually free for it —
                 `offerableTables` drops the ones already held over that span, and the ones too
                 small for the party. It is a convenience and nothing more: the page cannot know
                 about a booking made in the seconds since it rendered, so `TableReservation`
                 re-reads the overlap behind a row lock and refuses. Narrowing here saves the
                 common case from that refusal; it does not replace it. --}}
            <select v-if="offerableTables.length" :id="uid + '-table'" class="saffron-order-mode__outlet"
                    v-model="table" data-checkout-field="table_number">
                <option v-for="t in offerableTables" :key="t.label" :value="t.label">@{{ tableOptionLabel(t) }}</option>
            </select>

            {{-- Asked, but not answerable yet. A diner cannot say which table they are at until
                 the room is known, and inventing a list here would be inventing the room. --}}
            <p v-else-if="awaitingBranch" class="saffron-order-mode__hint mb-0">@{{ labels.branchFirst }}</p>

            {{-- The branch has tables and every one of them is spoken for at that time, or too
                 small for the party. **No `table_number` control renders at all**, which is the
                 honest state: there is no answer to give. Checkout refuses an empty table for a
                 booking, so a customer who ignores this and pays anyway is still stopped — but
                 they are told here, where the time and the party size are the two things they
                 can still change. --}}
            <p v-else-if="branchTables.length" class="saffron-order-mode__hint mb-0">@{{ labels.noneFree }}</p>

            {{-- The shop-wide fallback. Rendered by Blade rather than `v-if` when the count is
                 set, so only one `table_number` control is ever in the DOM — core's collector
                 keeps the last one it reads, and two would let the hidden one win. --}}
            @if($tables > 0)
            <select v-else :id="uid + '-table'" class="saffron-order-mode__outlet"
                    v-model="table" data-checkout-field="table_number">
                <option v-for="n in tables" :key="n" :value="String(n)">@{{ labels.tableOption.replace(':number', n) }}</option>
            </select>
            @else
            <input v-else :id="uid + '-table'" type="text" class="saffron-order-mode__table"
                   v-model="table" data-checkout-field="table_number"
                   :placeholder="labels.tablePlaceholder" maxlength="20" autocomplete="off">
            @endif
            </template>
        </div>
    </Teleport>
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

    // Where the table question goes: an element this theme owns, parked immediately after
    // core's branch list. Not *inside* `[data-co-pickup]` — core empties that box every time
    // it redraws the list, which would take the teleported control with it.
    //
    // Held in a ref so the template can wait: a Teleport whose target does not exist yet
    // warns and drops its content, and this script runs above the cart section, so on the
    // first pass core's summary is not in the DOM at all.
    const TABLE_SLOT_ID = 'saffron-dine-table-slot';
    const tableSlot     = ref(null);

    const seatTableSlot = () => {
        const box = document.querySelector('[data-co-pickup]');

        if (!box || !box.parentNode) return false;

        let slot = document.getElementById(TABLE_SLOT_ID);

        if (!slot) {
            slot = document.createElement('div');
            slot.id = TABLE_SLOT_ID;
        }

        // Re-seated rather than seated once: core re-renders the summary as the cart
        // validates, and a slot left behind an old node would put the table question back
        // above the branch it belongs to.
        if (box.nextElementSibling !== slot) {
            box.parentNode.insertBefore(slot, box.nextSibling);
        }

        tableSlot.value = TABLE_SLOT_ID;

        return true;
    };

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
        // The pickup-branch list (core's, since branches became pickup methods) sits above
        // the address panel — and the tiles must come above BOTH: the question "how do you
        // want it" has to precede the list its answer reveals, or the customer meets three
        // branches before being asked whether they are collecting at all.
        const pickupBox = document.querySelector('[data-co-fulfill]') || document.querySelector('[data-co-pickup]');
        const panel     = document.querySelector('[data-co-panel]');
        const schedule  = document.getElementById('saffron-order-schedule');

        // Inserted into the anchor's OWN parent: `insertBefore` requires the reference node to
        // be a direct child, and climbing to a card with `closest()` threw NotFoundError on
        // every one of the retries below.
        const anchor = (pickupBox && pickupBox.parentNode)
            ? pickupBox
            : ((panel && panel.parentNode)
                ? panel
                : ((schedule && schedule.parentNode === button.parentNode) ? schedule : button));

        if (root.nextElementSibling === anchor) return true;

        anchor.parentNode.insertBefore(root, anchor);

        return true;
    };

    // The script runs where the component is printed, above the cart section, so the target
    // does not exist on the first pass; core also re-renders the summary as the cart
    // validates. Re-checked for a few seconds, then left where the server put it.
    // Both seatings share one retry: they depend on the same summary appearing, and the
    // table slot must be re-seated on every core re-render, so the loop runs until BOTH are
    // settled rather than stopping at the first.
    // Both must run every pass — `&&` would stop calling the second once the first settled,
    // and the slot has to be re-seated after each of core's re-renders.
    const seatBoth = () => {
        const seated = seat();
        const slotted = seatTableSlot();

        return seated && slotted;
    };

    if (!seatBoth()) {
        let tries = 0;
        const timer = setInterval(() => {
            if (seatBoth() || ++tries > 40) clearInterval(timer);
        }, 100);
    }

    createApp({
        setup() {
            const mounted = ref(false);
            const modes   = payload.modes || ['delivery'];

            // **Delivery-or-pickup is this component's question and nobody else's.**
            //
            // For one afternoon the order gate asked it too, before the menu, and this ref was
            // seeded from what the gate had stored. That was one question in two places: the
            // gate's bar and these tiles could show different answers, and the customer had been
            // made to answer before seeing any food. The gate now asks only *which branch* —
            // the one thing that genuinely decides what can be cooked — and the mode is chosen
            // here, where the fee, the minimum and the address panel it governs already live.
            //
            // What D-3 actually objected to was the *cost* arriving after a full basket, not the
            // question arriving at the cart. The gate's bar states the minimums beside the branch
            // on every page, so the price is known before the food without a second gate.
            const mode    = ref(modes[0]);

            // ── What THIS branch can actually do (register O18a) ────────────────────
            // `offers_pickup` and `offers_delivery` are per outlet, and the branch was chosen at
            // the gate before the menu — so the tiles narrow to that branch's own doors. Without
            // this, a branch that does not deliver still showed Delivery and the customer walked
            // into a dead end the Outlets form promises cannot exist.
            //
            // Read from `localStorage` rather than wired between components: the gate is absent
            // on a single-branch shop, and a wire that breaks when one end is missing is worse
            // than a value that is simply not there. No stored branch, an unreadable store, or a
            // branch the payload does not list all fall through to the shop-wide offering.
            const gatedBranch = (() => {
                try {
                    const raw = localStorage.getItem('ovynt_order_gate');
                    const saved = raw ? JSON.parse(raw) : null;

                    return saved && typeof saved === 'object' ? saved.branch : null;
                } catch (e) { return null; }
            })();

            const doors = (payload.branchDoors || {})[gatedBranch] || null;

            // Dine-in rides on the counter: somebody eating in collects from the pass they are
            // sitting beside, so a branch with no collection has no dining room either. That
            // half is composed server-side by `Outlet::dinesIn()` and arrives as `dineIn`, so
            // this reads one flag per door rather than re-deriving the rule in the browser.
            const branchOffers = (m) => {
                if (!doors) return true;
                if (m === 'delivery') return !!doors.delivery;
                if (m === 'dine_in') return !!doors.dineIn;

                return !!doors.pickup;
            };

            // A mode this branch cannot do must never be the one selected — that is the dead end
            // itself, one step further on.
            if (!branchOffers(mode.value)) {
                const first = modes.find(branchOffers);

                if (first) mode.value = first;
            }

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

            // ── Which branch, and therefore which tables (O19) ──────────────────────
            //
            // The branch question belongs to CORE now — one pickup-type shipping method per
            // branch — so the theme learns the answer the only way it can: by reading which of
            // core's radios is checked. Delegated on `document` rather than bound to the radios
            // themselves, because core redraws that list whenever the summary re-renders and a
            // listener attached to a replaced node is a listener that stops firing.
            const methodId = ref(null);

            const readMethod = () => {
                const checked = document.querySelector('input[name="cms-co-pickup"]:checked');

                methodId.value = checked ? Number(checked.value) : null;
            };

            const branchTables = computed(() => {
                const outletId = payload.methodOutlets?.[methodId.value];

                if (!outletId) return [];

                const outlet = (payload.outlets || []).find(o => Number(o.id) === Number(outletId));

                return outlet?.tables || [];
            });

            // True only while the answer is genuinely unavailable: branches exist, none is
            // chosen, and the ones that exist have tables listed. A shop still on the shop-wide
            // count must fall through to it rather than be told to choose a branch it has
            // already chosen.
            const awaitingBranch = computed(() =>
                !methodId.value
                && Object.keys(payload.methodOutlets || {}).length > 0
                && (payload.outlets || []).some(o => (o.tables || []).length > 0)
            );

            // ── The branch chosen HERE, which need not be the one the gate chose ────
            //
            // `branchOffers` above narrows the tiles to the branch stored at the gate. This is
            // the other end of the same question: core's pickup list on the cart offers **every**
            // collecting branch, so a customer who answered the gate with Bangsar can still pick
            // KLCC here — and if KLCC has no dining room the panel would go on offering it
            // tables. Answered where the branch is actually chosen rather than assumed to equal
            // the gate's, because they are two separate controls and only one of them is ours.
            //
            // `false` while no branch is known, and for a branch the payload does not describe:
            // "I cannot tell" must never render as "this branch refuses you". `BranchDineInGuard`
            // is what actually holds; this only spares the customer meeting it at the payment
            // button, which is the complaint the whole ordering gate exists to answer.
            const branchClosedToDiners = computed(() => {
                const outletId = payload.methodOutlets?.[methodId.value];

                if (!outletId) return false;

                const doors = (payload.branchDoors || {})[outletId];

                return !!doors && !doors.dineIn;
            });

            // ── The time, read from the schedule block (O19, phase 2) ───────────────
            //
            // Which tables are free is a question about a MOMENT, so nothing below can be
            // answered until the customer has named one — and the control that names it belongs
            // to `OrderSchedule`, a separate app seated further down the summary. Read from the
            // DOM for the same reason that component reads `dining` from the DOM: the two blocks
            // share the page rather than a wire, so either can be absent without breaking the
            // other. The schedule block dispatches a bubbling `change` on its own hidden input
            // whenever the value moves, which is what makes this reactive.
            const scheduleTick = ref(0);

            const scheduledFor = computed(() => {
                void scheduleTick.value;

                const el = document.querySelector('[data-checkout-field="scheduled_at"]');

                return el ? String(el.value || '') : '';
            });

            const bookings = payload.bookings === true;

            const pad2 = (n) => String(n).padStart(2, '0');

            // The span a booking made right now would occupy. Null unless the shop takes
            // bookings AND a full date-and-time was chosen: the schedule block posts a bare date
            // when the customer picked a day the shop turns out to be shut for, and a date with
            // no time names no span.
            const bookingSpan = computed(() => {
                if (!bookings) return null;

                const chosen = scheduledFor.value;

                if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(chosen)) return null;

                const outletId = payload.methodOutlets?.[methodId.value];
                const minutes  = (payload.bookingMinutes || {})[outletId] || payload.bookingMinutesDefault;

                const [datePart, timePart] = chosen.split(' ');
                const [y, mo, d] = datePart.split('-').map(Number);
                const [h, mi]    = timePart.split(':').map(Number);

                // Built through a Date purely to carry the minutes over an hour and a month
                // boundary. The browser's own timezone is irrelevant — the value goes in as a
                // wall clock and comes straight back out as one, which is the same wall clock
                // `TableReservation` stores.
                const end = new Date(y, mo - 1, d, h, mi + minutes);

                return [
                    chosen,
                    end.getFullYear() + '-' + pad2(end.getMonth() + 1) + '-' + pad2(end.getDate())
                        + ' ' + pad2(end.getHours()) + ':' + pad2(end.getMinutes()),
                ];
            });

            // ── How many of you ─────────────────────────────────────────────────────
            // Two is where a restaurant's booking form starts, and it is the commonest answer.
            const covers = ref(2);

            // Asked only of somebody actually reserving. A walk-in has already taken their
            // seats, and asking how many they are would be asking a question with no effect.
            const askCovers = computed(() => bookings && !!bookingSpan.value);

            // As large as the biggest table in the room. Offering a party of twelve where the
            // largest table seats four is offering a booking that cannot be honoured. Twelve is
            // the fallback for a shop that never recorded seat counts — there the writer has
            // nothing to check the party against either, so any number is as good as any.
            const maxCovers = computed(() => {
                const seats = branchTables.value.map(t => t.seats).filter(n => n > 0);

                return seats.length ? Math.max(...seats) : 12;
            });

            // A party that no longer fits anything at the newly-chosen branch is brought back
            // into range rather than left naming a number the list cannot satisfy.
            watch(maxCovers, (max) => { if (covers.value > max) covers.value = max; });

            // ── The tables actually worth offering ──────────────────────────────────
            // Two filters, both of which mirror a refusal `TableReservation` would otherwise
            // deliver at the payment button: a table too small for the party, and a table
            // already held over the span. Neither is a guarantee — the page cannot see a
            // booking made since it rendered — which is why the writer checks again under a
            // row lock and this only ever narrows.
            const offerableTables = computed(() => {
                const list = branchTables.value;

                if (!list.length) return [];

                const span     = bookingSpan.value;
                const outletId = payload.methodOutlets?.[methodId.value];
                const held     = span ? ((payload.held || {})[outletId] || {}) : {};
                const party    = askCovers.value ? covers.value : 0;

                return list.filter(t => {
                    // A table with no recorded seat count fits anybody: an unfilled column is
                    // "unknown", and treating it as zero would empty the list of a shop that
                    // simply never typed the numbers in.
                    if (party && t.seats > 0 && t.seats < party) return false;

                    if (!span) return true;

                    const taken = held[String(t.label).toLowerCase()] || [];

                    // Half-open, exactly as `TableBooking::scopeOverlapping` writes it: a
                    // booking ending at 20:00 does not collide with one starting at 20:00,
                    // because that is a table turning. The stamps are fixed-width
                    // `YYYY-MM-DD HH:MM`, so a string comparison IS a chronological one.
                    return !taken.some(s => s[0] < span[1] && s[1] > span[0]);
                });
            });

            const tableOptionLabel = (t) => (
                t.seats > 0 ? t.label + ' · ' + payload.labels.seatsHint.replace(':count', t.seats) : t.label
            );

            // A table chosen at one branch cannot mean anything at another, so switching
            // branches clears it rather than carrying a number into a room that may not have
            // one. Watched on the OFFERABLE list rather than the branch's whole one: a table
            // that has just been filtered away — the party grew, the time moved onto somebody
            // else's booking — must not stay selected in a control that no longer lists it, or
            // `table_number` would post a table the customer can no longer see.
            watch(offerableTables, (list) => {
                if (list.length) {
                    if (!list.some(t => t.label === table.value)) table.value = list[0].label;
                } else if (methodId.value) {
                    table.value = '';
                }
            });

            // **Watched on `mode`, not on `fulfillmentType`, and that distinction is the whole
            // bug it fixes.** Pickup and Dine in are the SAME fulfilment type — that is the
            // design — so switching between those two tiles leaves `fulfillmentType`
            // untouched and fired nothing. Core, which words its branch question from the
            // `dining` key, was therefore never told to redraw it: choosing Dine in and then
            // Pickup again left a collecting customer reading "Which branch are you dining
            // at?". Caught in a browser; no assertion would have seen it, because the posted
            // payload was right the whole time and only the wording was stale.
            watch(mode, () => {
                nextTick(() => {
                    modeInput.value?.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

            // Core's branch radios, read wherever they end up. `change` bubbles, so one
            // listener survives every redraw of that list.
            //
            // The tick is bumped on EVERY change, not only on the branch radios: the other DOM
            // this component reads is the schedule block's `scheduled_at` input, which belongs
            // to a different app and can move for reasons this one cannot enumerate. Bumping a
            // counter costs a recompute of two small computeds; missing a bump costs a table
            // list that still offers a table somebody else booked.
            document.addEventListener('change', (e) => {
                if (e.target && e.target.name === 'cms-co-pickup') readMethod();

                scheduleTick.value++;
            });

            // And once at mount: core auto-selects the only branch when a shop has one, which
            // fires no event because nobody clicked it.
            onMounted(() => nextTick(readMethod));

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

            // Where to collect. A multi-branch shop shows NO card here any more: the branch
            // choice lives in core's pickup-method list now, this component cannot know
            // which one was picked, and stating the first branch's address under a customer
            // who chose the second would be worse than silence — put each branch's address
            // in its method's subtitle instead, which the list renders under the name.
            const collectAddress = computed(() => {
                if (outlets.length > 1) return '';
                if (outlets.length === 1) return outlets[0].address || payload.pickupAddress || '';
                return payload.pickupAddress || '';
            });

            // The clipboard. `navigator.clipboard` needs a secure context, and a shop served
            // over plain http (a LAN box at the counter) still deserves a working button, so
            // the deprecated-but-everywhere textarea path stays as the fallback rather than
            // the button quietly doing nothing.
            const copied = ref(false);
            let copiedTimer = null;

            const fallbackCopy = (text) => {
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                let ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                area.remove();
                return ok;
            };

            const copyAddress = () => {
                const text = collectAddress.value;
                if (!text) return;

                const done = () => {
                    copied.value = true;
                    clearTimeout(copiedTimer);
                    copiedTimer = setTimeout(() => { copied.value = false; }, 2000);
                };

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done, () => { if (fallbackCopy(text)) done(); });
                } else if (fallbackCopy(text)) {
                    done();
                }
            };

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
                mode, modes, fulfillmentType, modeInput, branchOffers,
                visible,
                labels: payload.labels,
                pickupAddress: payload.pickupAddress,
                outlets, outletId, chosenOutlet,
                collectAddress, copied, copyAddress,
                tables, table, tableSlot, branchTables, awaitingBranch, branchClosedToDiners,
                covers, askCovers, maxCovers, offerableTables, tableOptionLabel,
                noCutlery,
                uid: '{{ $uid }}',
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
@endif
