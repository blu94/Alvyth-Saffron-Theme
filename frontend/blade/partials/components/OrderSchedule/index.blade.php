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

    <p class="saffron-schedule__closed" v-if="!open">@{{ labels.closed }}</p>

    {{-- Closed means there is nothing to be as soon as possible about, so the choice
         collapses to the one the customer can actually make: a future slot. --}}
    <div class="saffron-schedule__modes" role="radiogroup" aria-label="{{ __('When do you want it') }}" v-if="open">
        <label class="saffron-schedule__mode" :class="{ 'is-active': mode === 'asap' }">
            <input type="radio" name="saffron-schedule-mode" value="asap" v-model="mode">
            <span>@{{ labels.asap }}</span>
        </label>
        <label class="saffron-schedule__mode" :class="{ 'is-active': mode === 'later' }">
            <input type="radio" name="saffron-schedule-mode" value="later" v-model="mode">
            <span>@{{ labels.schedule }}</span>
        </label>
    </div>

    {{-- Days are chips, not a dropdown. A select hides its options, and a shop offering three
         days made the customer open a menu to discover how little was in it — the same choice
         drawn two different ways, 40px under the pills above. Every offerable day is visible
         at a glance and one tap away, which is what every delivery platform does. They wrap
         rather than scrolling: in a 480px summary column a scrolled row hid its last days
         behind a gesture nobody discovers. A week is as far as chips go — past that they stop
         being a row and become a wall, which is where the calendar below takes over. --}}
    <div class="saffron-schedule__slots" v-if="mode === 'later'">
        <div class="saffron-schedule__field">
            <span class="saffron-schedule__label">@{{ labels.day }}</span>
            <div class="saffron-schedule__days" role="radiogroup" :aria-label="labels.day">
                {{-- Bound to `dayChoice`, not to `day`. `day` became a computed when the date
                     field arrived, and a computed has no setter — so these radios silently
                     discarded every write and the row stuck on whatever was last chosen,
                     leaving no way back from Another date to Today. --}}
                <label
                    v-for="d in days"
                    :key="d.date"
                    class="saffron-schedule__day"
                    :class="{ 'is-active': dayChoice === d.date }"
                >
                    <input type="radio" name="saffron-schedule-day" :value="d.date" v-model="dayChoice">
                    <span class="saffron-schedule__day-label">@{{ d.label }}</span>
                    <span class="saffron-schedule__day-from">@{{ labels.from }} @{{ d.slots[0] }}</span>
                </label>

                {{-- Anything past the quick week — a party in September, catering in November.
                     The horizon is the operator's setting and the field is bounded by it, so
                     the customer cannot name a date the checkout guard would refuse. --}}
                <label class="saffron-schedule__day saffron-schedule__day--other" :class="{ 'is-active': picking }">
                    <input type="radio" name="saffron-schedule-day" value="__other" v-model="dayChoice">
                    <span class="saffron-schedule__day-label">@{{ labels.otherDate }}</span>
                    <span class="saffron-schedule__day-from">@{{ labels.upTo }} @{{ maxDateLabel }}</span>
                </label>
            </div>

            {{-- The theme's own month grid, not `<input type="date">`. The native field drops a
                 browser-chrome panel into a card that matches nothing around it, and — the
                 reason that matters more — it honours only min and max, so the days the shop
                 is shut looked exactly like the days it is open. Everything needed to mark
                 them is already on the page (the weekly pattern and the dated overrides), so
                 a closed day is drawn as closed and cannot be chosen at all. Built here rather
                 than pulled from a CDN, the same rule the animation system follows. --}}
            <div class="saffron-schedule__date" v-if="picking">
                <div class="saffron-cal" role="group" :aria-label="labels.date">
                    <div class="saffron-cal__head">
                        <button
                            type="button"
                            class="saffron-cal__nav"
                            :disabled="!canPrev"
                            :aria-label="labels.prevMonth"
                            @click="shiftMonth(-1)"
                        >&lsaquo;</button>

                        <span class="saffron-cal__month" aria-live="polite">@{{ monthLabel }}</span>

                        <button
                            type="button"
                            class="saffron-cal__nav"
                            :disabled="!canNext"
                            :aria-label="labels.nextMonth"
                            @click="shiftMonth(1)"
                        >&rsaquo;</button>
                    </div>

                    <div class="saffron-cal__dows" aria-hidden="true">
                        <span v-for="w in dowLabels" :key="w">@{{ w }}</span>
                    </div>

                    <div class="saffron-cal__grid">
                        <button
                            v-for="cell in calendarCells"
                            :key="cell.iso"
                            type="button"
                            class="saffron-cal__day"
                            :class="{
                                'is-outside': cell.outside,
                                'is-closed': cell.closed && !cell.outside,
                                'is-today': cell.today,
                                'is-selected': cell.iso === pickedDate,
                            }"
                            :disabled="cell.disabled"
                            :title="cell.title"
                            :aria-label="cell.label"
                            :aria-pressed="cell.iso === pickedDate"
                            @click="pickedDate = cell.iso"
                        >@{{ cell.day }}</button>
                    </div>
                </div>
                {{-- A native date field honours min and max and nothing else — it cannot grey
                     out the days the shop is shut, and there is no endpoint to ask about one.
                     So a closed date is answered the instant it is chosen, in the shop's own
                     words where a holiday carries a reason, and checkout refuses it besides. --}}
                <p class="saffron-schedule__note" v-if="pickedDate && !slotsForDay.length">
                    @{{ closedThatDay ? (overrideReason || labels.shut) : labels.noSlots }}
                </p>
                <p class="saffron-schedule__ok" v-else-if="pickedDate">
                    @{{ labels.openThat }} @{{ slotsForDay[0] }}–@{{ slotsForDay[slotsForDay.length - 1] }}
                </p>
            </div>
        </div>

        {{-- Time stays a select: a day carries twenty-odd half-hour slots, which is the one
             thing a dropdown is genuinely better at than a row of chips. --}}
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
            const open   = payload.open !== false;

            // Closed shops start on the first slot they can honour rather than on ASAP, which
            // the markup above does not even offer them. `time` is seeded here for the same
            // reason: the watcher that normally fills it only fires on a change, so a closed
            // shop would otherwise post an empty value and be judged as an ASAP order — and
            // refused by the server, which is exactly the confusion this avoids.
            const mode    = ref(open ? 'asap' : 'later');
            const time    = ref(open ? '' : (days[0] && days[0].slots[0] ? days[0].slots[0] : ''));
            const mounted = ref(false);

            // `dayChoice` is what the radio row writes: either a quick day's date, or the
            // sentinel that reveals the date field. `day` below is the date actually ordered
            // for, which is why the two are separate — the sentinel is not a date.
            const dayChoice  = ref(days[0] ? days[0].date : '');
            const pickedDate = ref('');
            const picking    = computed(() => dayChoice.value === '__other');
            const day        = computed(() => (picking.value ? pickedDate.value : dayChoice.value));

            const minDate = payload.minDate;
            const maxDate = payload.maxDate;

            const fmtDate = (iso) => {
                const d = new Date(iso + 'T00:00:00');
                return isNaN(d) ? iso : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
            };
            const maxDateLabel = fmtDate(maxDate);

            // ── Resolving a named date ───────────────────────────────────────────────
            // The quick days arrive precomputed. Any other date is derived here from the
            // weekly pattern and the dated overrides, applying the same precedence the server
            // does — an override wins its date outright, weekly hours fill the rest. This is
            // presentation: `ServiceWindowGuard` derives it again at checkout and its answer
            // is the one that decides, so a disagreement costs a refusal, never a bad order.
            const toMinutes = (hhmm) => {
                const [h, m] = hhmm.split(':').map(Number);
                return h * 60 + m;
            };
            const pad = (n) => String(n).padStart(2, '0');

            const spansFor = (iso) => {
                const override = payload.overrides[iso];
                if (override) return override.spans;
                const dow = new Date(iso + 'T00:00:00').getDay();
                return payload.pattern[dow] || [];
            };

            const closedThatDay = computed(() => picking.value && pickedDate.value && spansFor(pickedDate.value).length === 0);
            const overrideReason = computed(() => {
                const o = pickedDate.value ? payload.overrides[pickedDate.value] : null;
                return o ? o.reason : null;
            });

            const computeSlots = (iso) => {
                const [nowDate, nowTime] = payload.nowLocal.split(' ');
                const isToday = iso === nowDate;
                const earliest = toMinutes(nowTime) + payload.lead;
                const out = [];

                spansFor(iso).forEach(span => {
                    const close = toMinutes(span.closes);
                    for (let m = toMinutes(span.opens); m + payload.step <= close; m += payload.step) {
                        // Today alone has a past; a future date offers its whole service.
                        if (isToday && m < earliest) continue;
                        out.push(pad(Math.floor(m / 60)) + ':' + pad(m % 60));
                    }
                });

                return [...new Set(out)].sort();
            };

            const slotsForDay = computed(() => {
                if (!day.value) return [];
                const quick = days.find(d => d.date === day.value);
                return quick ? quick.slots : computeSlots(day.value);
            });

            // ── The month grid ───────────────────────────────────────────────────────
            // Dates are handled as `YYYY-MM-DD` strings and only ever turned into a Date at
            // noon. Midnight plus a timezone offset lands on the previous day in half the
            // world, which is how a calendar quietly shows the wrong month to some visitors.
            const isoOf = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            const dateOf = (iso) => new Date(iso + 'T12:00:00');

            const calMonth = ref(dateOf(payload.minDate));

            watch(pickedDate, (iso) => { if (iso) calMonth.value = dateOf(iso); });

            const monthLabel = computed(() =>
                calMonth.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
            );

            // Weekday initials in the visitor's own locale and week order is a bigger job than
            // this screen needs; Sunday-first matches how the hours are stored (0 = Sunday).
            const dowLabels = (() => {
                const out = [];
                for (let i = 0; i < 7; i++) {
                    const d = new Date(Date.UTC(2024, 8, 1 + i, 12));
                    out.push(d.toLocaleDateString(undefined, { weekday: 'narrow' }));
                }
                return out;
            })();

            const firstOfMonth = (d) => new Date(d.getFullYear(), d.getMonth(), 1, 12);
            const sameMonth = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth();

            const canPrev = computed(() => firstOfMonth(calMonth.value) > dateOf(payload.minDate));
            const canNext = computed(() => {
                const next = new Date(calMonth.value.getFullYear(), calMonth.value.getMonth() + 1, 1, 12);
                return next <= dateOf(payload.maxDate);
            });

            const shiftMonth = (by) => {
                calMonth.value = new Date(calMonth.value.getFullYear(), calMonth.value.getMonth() + by, 1, 12);
            };

            const calendarCells = computed(() => {
                const first = firstOfMonth(calMonth.value);
                const start = new Date(first);
                start.setDate(1 - first.getDay()); // back to the Sunday that opens the grid

                const cells = [];

                // Built a week at a time, and a week with nothing selectable in it is dropped
                // rather than drawn. A month grid is always six rows, and an out-of-range day
                // is invisible but still occupies its cell — so the last month of the horizon
                // ended in a slab of white space four rows deep. Whole weeks go; the part
                // weeks stay, because their blanks are what keeps the columns under the right
                // weekday headings.
                for (let w = 0; w < 6; w++) {
                    const week = [];
                    let usable = false;

                    for (let i = 0; i < 7; i++) {
                        const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + w * 7 + i, 12);
                        const iso = isoOf(d);
                        const outside = iso < payload.minDate || iso > payload.maxDate || !sameMonth(d, calMonth.value);
                        const closed = computeSlots(iso).length === 0;
                        const override = payload.overrides[iso];

                        if (!outside) usable = true;

                        week.push({
                            iso,
                            day: d.getDate(),
                            outside,
                            closed,
                            today: iso === payload.nowLocal.split(' ')[0],
                            disabled: outside || closed,
                            label: d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }),
                            // The shop's own words for why a day cannot be picked, where it
                            // wrote any — a bare disabled square invites a phone call.
                            title: outside ? '' : (closed ? ((override && override.reason) || labels.shut) : ''),
                        });
                    }

                    if (usable) cells.push(...week);
                }

                return cells;
            });

            // Changing the day must never leave a time the new day does not offer.
            watch(day, () => { time.value = slotsForDay.value[0] || ''; });
            watch(mode, () => {
                if (mode.value === 'later' && !time.value) time.value = slotsForDay.value[0] || '';
            });

            // An empty value means ASAP, so a scheduled intent must never produce one. It did:
            // picking a date the shop is shut for left `time` empty, the field posted '', and
            // checkout read "as soon as possible" — the customer chose Christmas Day and
            // ordered lunch today, with nothing on screen to say so. Without a time the date
            // alone is posted; the guard resolves it to midnight, finds the shop closed, and
            // refuses with the day's own reason. A refusal is recoverable, a silent swap is not.
            const fieldValue = computed(() => {
                if (mode.value !== 'later' || !day.value) return '';

                return time.value ? day.value + ' ' + time.value : day.value;
            });

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

            return {
                days, labels, open, mode, day, time, slotsForDay, fieldValue, visible,
                dayChoice, pickedDate, picking, minDate, maxDate, maxDateLabel,
                closedThatDay, overrideReason,
                monthLabel, dowLabels, calendarCells, canPrev, canNext, shiftMonth,
            };
        },
    }).mount('#saffron-order-schedule');
})();
</script>
