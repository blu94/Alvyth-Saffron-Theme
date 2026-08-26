<div id="{{ $uid }}" class="saffron-gate" v-cloak>
    {{-- THE BAR. States the branch on every page, and beside it what it costs to order at all.

         The cost is here because that was D-3's real complaint: not that "delivery or pickup?"
         was asked at the cart, but that the *price of the answer* only appeared once a basket
         was full. Stating the minimums next to the branch puts the cost before the food without
         putting a second question in front of the menu. A shop that set no minimum states none
         and the bar is just the branch. --}}
    <div class="saffron-gate__bar" v-if="chosen">
        <span class="saffron-gate__summary">
            <svg class="saffron-gate__mark" viewBox="0 0 24 24" width="15" height="15" stroke="currentColor"
                 stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 21s-7-4.9-7-10a7 7 0 0 1 14 0c0 5.1-7 10-7 10z"></path>
                <circle cx="12" cy="11" r="2.4"></circle>
            </svg>
            <span class="saffron-gate__branch">@{{ branchLabel }}</span>
            <template v-if="costLine">
                <span class="saffron-gate__sep" aria-hidden="true">·</span>
                <span class="saffron-gate__cost">@{{ costLine }}</span>
            </template>
        </span>
        <button type="button" class="saffron-gate__change" @click="open = true">@{{ labels.change }}</button>
    </div>

    {{-- THE PANEL. One question. It opens on a first visit with nothing stored, and whenever the
         customer asks to change. Not dismissable on the first visit — the whole point is that the
         answer exists before the menu does — but freely dismissable afterwards, because by then
         there is an answer to keep. --}}
    <div class="saffron-gate__scrim" v-if="open" @click.self="dismiss">
        <div class="saffron-gate__panel" role="dialog" aria-modal="true" :aria-label="labels.heading">
            <h2 class="saffron-gate__title">@{{ labels.heading }}</h2>
            <p class="saffron-gate__sub">@{{ labels.sub }}</p>

            <div class="saffron-gate__branches" role="radiogroup" :aria-label="labels.branch">
                <label v-for="b in branches" :key="b.id" class="saffron-gate__branch-option"
                       :class="{ 'is-active': draftBranch === b.id }">
                    <input type="radio" name="saffron-gate-branch" :value="b.id" v-model="draftBranch">
                    <span class="saffron-gate__branch-name">@{{ b.title }}</span>
                    <span class="saffron-gate__branch-address" v-if="b.address">@{{ b.address }}</span>
                </label>
            </div>

            {{-- WHAT CANNOT COME WITH YOU. Shown before the switch is applied, never after — a
                 basket silently emptied on a branch change is work the customer did being thrown
                 away without being told. Naming the dishes is what makes the choice a choice. --}}
            <div class="saffron-gate__drop" v-if="dropping.length">
                <p class="saffron-gate__drop-title">@{{ labels.dropTitle }}</p>
                <p class="saffron-gate__drop-body">@{{ dropMessage }}</p>
            </div>

            <div class="saffron-gate__actions">
                <button type="button" class="saffron-gate__confirm" @click="confirm">
                    @{{ dropping.length ? labels.dropGo : labels.confirm }}
                </button>
                <button type="button" class="saffron-gate__cancel" v-if="chosen" @click="dismiss">
                    @{{ dropping.length ? stayLabel : labels.close }}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const { createApp, ref, computed, watch, onMounted } = Vue;

    const payload = @json($payload);
    const STORE_KEY = 'ovynt_order_gate';

    createApp({
        setup() {
            const branches = payload.branches || [];
            const labels   = payload.labels;

            // What the customer settled on, and what they are currently considering. Two
            // variables rather than one, because the panel must be abandonable: editing the live
            // choice would re-scope the menu underneath somebody who then pressed Close.
            const branch = ref(null);
            const open   = ref(false);

            const draftBranch = ref(branches.length ? branches[0].id : null);

            const chosen = computed(() => branch.value !== null);

            const branchOf = (id) => branches.find(b => Number(b.id) === Number(id)) || null;

            const branchLabel = computed(() => branchOf(branch.value)?.title || '');

            // The cancel button's wording while dishes would be dropped. It names the branch the
            // customer stays on, so the pair reads as one decision with two outcomes — "Remove
            // them and switch" against "Stay at KLCC" — rather than one about dishes and one
            // about a basket. Falls back to a branch-less sentence if the title is somehow empty,
            // because a button reading "Stay at " is worse than a vaguer one.
            const stayLabel = computed(() => (
                branchLabel.value
                    ? labels.dropStay.replace(':branch', branchLabel.value)
                    : labels.close
            ));

            // The minimums, joined only where they exist. A shop with neither gets no separator
            // and no empty half — the bar is then simply the branch.
            const costLine = computed(() => {
                const parts = [];

                if (payload.minimums?.delivery) {
                    parts.push(labels.minDelivery.replace(':amount', payload.minimums.delivery));
                }

                if (payload.minimums?.pickup) {
                    parts.push(labels.minPickup.replace(':amount', payload.minimums.pickup));
                }

                return parts.join(' · ');
            });

            // ── Reading and writing the choice ──────────────────────────────────────
            // Stored beside the cart and in the same shape. Every read is wrapped, because a
            // private window, a cleared store or a browser set to block site data all throw
            // rather than return null.
            const load = () => {
                try {
                    const raw = localStorage.getItem(STORE_KEY);
                    if (!raw) return null;
                    const parsed = JSON.parse(raw);
                    return (parsed && typeof parsed === 'object') ? parsed : null;
                } catch (e) { return null; }
            };

            // **The cookie is the authority, and `localStorage` is only a fallback.**
            //
            // Every server-side decision on this page was made from the `ovynt_branch` cookie:
            // which rows the grids were filled with, whether the dish sheet drew an Add button,
            // what search returned. The bar, until now, was drawn from `localStorage` alone — two
            // stores with nothing keeping them in step.
            //
            // They drift in both directions and each has a visible symptom. Clear site data in a
            // private window and `localStorage` goes while the cookie stays: the gate re-opens and
            // asks again over a page that is already scoped. Clear cookies and keep
            // `localStorage`: the bar names a branch the request was never scoped to, so an
            // exclusive dish is on screen and addable at the wrong branch — which is exactly the
            // report that could not be reproduced from a fresh load, because a fresh load is the
            // one case where the two agree.
            //
            // Reading the cookie first makes the bar describe the page in front of the customer
            // rather than a choice made on some earlier one. `apply()` then writes both back, so
            // whichever store had gone stale is healed on the next paint.
            const cookieBranch = () => {
                try {
                    const match = document.cookie.match(/(?:^|;\s*)ovynt_branch=([^;]*)/);

                    if (!match) return null;

                    const id = Number(decodeURIComponent(match[1]));

                    return Number.isFinite(id) && id > 0 ? id : null;
                } catch (e) { return null; }
            };

            const save = () => {
                try {
                    localStorage.setItem(STORE_KEY, JSON.stringify({ branch: branch.value }));
                } catch (e) { /* full, blocked, or private */ }

                // **Mirrored into a cookie so the SERVER knows too.** `localStorage` never
                // leaves the browser, so a page load cannot be scoped from it — the dish sheet
                // would keep offering an Add button for a dish this branch cannot make, and
                // search would keep returning it. The cookie is what lets `BranchScope` answer
                // on the request that renders the page.
                //
                // Deliberately not `Secure`: a shop served over plain http on a counter machine
                // must work too, and this carries an outlet id rather than anything private.
                // `SameSite=Lax` because it only ever needs to survive a normal navigation.
                try {
                    const year = 60 * 60 * 24 * 365;

                    document.cookie = 'ovynt_branch=' + encodeURIComponent(branch.value ?? '')
                        + ';path=/;max-age=' + (branch.value ? year : 0) + ';SameSite=Lax';
                } catch (e) { /* the menu simply stays unscoped server-side */ }
            };

            // ── The branch's menu ───────────────────────────────────────────────────
            // `menu` is null for a branch that restricts nothing, and that is not the same as an
            // empty list — null serves everything, empty serves nothing. Reading them as one
            // would either blank every existing shop or ignore the operator's switch.
            // The dishes to HIDE at a branch: exclusive to some other branch. Always an array, and
            // an empty one hides nothing.
            // This replaced an allow-list on 2026-08-25: `menuOf` returned either the branch's
            // whole menu or `null`, and every reader had to remember that `null` meant "show
            // everything" while `[]` meant "show nothing". Two readers, two chances to invert a
            // shop's entire menu by getting one comparison backwards.
            const hiddenOf = (id) => {
                const b = branchOf(id);
                return (b && Array.isArray(b.hidden)) ? b.hidden : [];
            };

            const servedBy = (id, productId) => {
                return ! hiddenOf(id).includes(Number(productId));
            };

            // ── What the basket would lose ──────────────────────────────────────────
            const cartLines = () => {
                try { return window.OvyntStore ? window.OvyntStore.cartList : []; } catch (e) { return []; }
            };

            const dropping = computed(() => {
                if (!draftBranch.value) return [];

                return cartLines()
                    .filter(line => !servedBy(draftBranch.value, line.id))
                    .map(line => String(line.title || ''))
                    .filter(Boolean);
            });

            const dropMessage = computed(() => {
                const names = dropping.value;
                const list  = names.length === 1
                    ? names[0]
                    : names.slice(0, -1).join(', ') + ' ' + labels.and + ' ' + names[names.length - 1];

                return labels.dropBody
                    .replace(':dishes', list)
                    .replace(':branch', branchOf(draftBranch.value)?.title || '');
            });

            // ── Scoping what is on the page ─────────────────────────────────────────
            // Presentation only, and the honest limit is stated in the driver: the catalogue is
            // still in the HTML, and `BranchMenuGuard` is what actually refuses. This stops the
            // customer meeting that refusal at the payment button, which is the whole job.
            const scopeMenu = () => {
                if (!chosen.value) return;

                const hidden = hiddenOf(branch.value);

                document.querySelectorAll('[data-dish-id]').forEach(el => {
                    const id = Number(el.getAttribute('data-dish-id'));
                    const ok = ! hidden.includes(id);

                    // **Hide the COLUMN, not only the card.** `display: none` on the card leaves
                    // the grid column it sits in holding its slot, so the row keeps a hole where
                    // the dish was and the surviving dishes cannot close up beside each other.
                    // That is what a customer saw: two blank boxes in a row of three.
                    //
                    // Only the IMMEDIATE parent is considered. `closest('[class*="col-"]')` would
                    // be tidier and is a trap — with a card that is not in a column it walks up to
                    // the page's own content column and blanks the whole region.
                    //
                    // The server scopes the listing queries now (`BranchScope::constrain`), so on
                    // an ordinary page load no unserved card is rendered at all. This path is what
                    // handles the branch being CHANGED without a reload, where the markup on screen
                    // was built for the branch before it.
                    const parent = el.parentElement;
                    const inColumn = !!parent && /(^|\s)col(-[A-Za-z0-9]+)*(\s|$)/.test(parent.className || '');

                    el.classList.toggle('saffron-dish--offsite', !ok);

                    if (inColumn) {
                        parent.classList.toggle('saffron-dish--offsite', !ok);
                    }
                });
            };

            // **This component mounts before the dishes exist.** It is printed at the top of the
            // layout, above the header and far above whatever section renders the menu, so its
            // script runs while the rest of the page is still parsing. Scoping once at mount
            // therefore scoped nothing: a returning customer's choice was restored, the bar named
            // the branch, and every dish was still on show. Caught in a browser on a reload, not
            // by any assertion — the first visit worked perfectly, because by then the customer
            // had been looking at the page long enough for it to have finished loading.
            //
            // An observer rather than a retry loop. Dishes do not merely arrive late: a filtered
            // grid, a category switch or a section that mounts its own Vue app all replace them
            // after the fact, and a loop that gives up after a few seconds would leave those
            // unscoped. Batched into a frame so a grid inserting thirty cards costs one pass.
            let scopeQueued = false;

            const scopeSoon = () => {
                if (scopeQueued) return;

                scopeQueued = true;

                requestAnimationFrame(() => {
                    scopeQueued = false;
                    scopeMenu();
                });
            };

            const watchForDishes = () => {
                try {
                    new MutationObserver(scopeSoon).observe(document.body, {
                        childList: true,
                        subtree: true,
                    });
                } catch (e) {
                    // No observer is survivable: the menu shows everything, and the guard still
                    // refuses server-side. The page must not break over a convenience.
                }
            };

            // ── Telling the cart which branch was chosen ────────────────────────────
            // Core owns the branch question at the cart — one pickup method per branch — so the
            // answer given here selects the matching method rather than asking again. Dispatched
            // as a real change event, because core re-renders its summary from one and assigning
            // a property fires nothing.
            //
            // Only the branch is seeded. **Delivery-or-pickup is the cart's own question**, and
            // it is deliberately not pre-answered here: the mode decides the fee, the minimum and
            // the address panel, all of which live where the cart already asks.
            const seedCartMethod = () => {
                if (!branch.value) return;

                const wanted = Object.keys(payload.methodOutlets || {})
                    .find(methodId => Number(payload.methodOutlets[methodId]) === Number(branch.value));

                if (!wanted) return;

                const radio = document.querySelector('input[name="cms-co-pickup"][value="' + wanted + '"]');

                if (radio && !radio.checked) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };

            const apply = () => {
                save();
                publishMenu();
                scopeMenu();
                seedCartMethod();
                rerenderIfDishRefused();
            };

            // **A dish page cannot re-scope itself, so it is re-fetched.**
            //
            // Everything else on a page is a card the gate can hide. A dish page is different: the
            // whole screen is one dish, and whether it may be sold was decided by the SERVER when
            // it rendered — the Add form is simply absent when the branch cannot make it. Hiding
            // the card is not an option (a blank page), and re-deriving "may I sell this" in the
            // browser would put a second copy of the rule where the two can drift apart.
            //
            // So the page is reloaded, and the server answers again. That is affordable precisely
            // because it happens only when a customer has just deliberately changed branch AND the
            // dish in front of them is one the new branch cannot cook — not on every switch.
            //
            // It is a convenience either way: `ModifierPricing` refuses the line when the cart is
            // priced, so a customer who never reloads still cannot buy it.
            //
            // > [!WARNING]
            // > **On the MOUNT path this is inert, by construction — and it must stay that way.**
            // > `apply()` calls it from `onMounted`, but this component is printed at the top of
            // > the layout: measured on a dish page, the gate's mount element sits at byte 9904
            // > and `data-dish-sheet-id` at byte 60544, so the sheet is not in the DOM yet and
            // > `if (!sheet) return` takes it. It therefore fires only from `confirm()`, which is
            // > exactly the deliberate branch change the note above describes.
            // >
            // > **Do not "fix" that by re-running it on `window.load` or from the observer, the
            // > way `scopeSoon()` is.** The predicate asks only *is this dish hidden at the chosen
            // > branch*, never *did the server already refuse it* — and after a reload the answer
            // > is still yes, so the page would reload for ever. Anything that widens when this
            // > runs has to compare against the branch the SERVER rendered with (the `ovynt_branch`
            // > cookie as it was at parse time, before `save()` overwrites it) and reload only on
            // > a genuine difference.
            const rerenderIfDishRefused = () => {
                try {
                    const sheet = document.querySelector('[data-dish-sheet-id]');

                    if (!sheet) return;

                    const id = Number(sheet.getAttribute('data-dish-sheet-id'));

                    if (!id || !hiddenOf(branch.value).includes(id)) return;

                    window.location.reload();
                } catch (e) { /* the pricer and the checkout guard both still refuse */ }
            };

            // **What the page already rendered cannot re-render itself.** The cookie scopes the
            // NEXT request, so a customer who switches branch and immediately searches would get
            // the previous branch's list — the drawer baked its ids at render time. Publishing
            // the chosen branch's menu here lets anything already mounted read the current answer
            // instead of the one it was born with.
            //
            // `undefined` means "no gate on this page"; `null` means "this branch serves
            // everything". A reader must be able to tell those apart, so the key is only ever set
            // once a choice exists.
            const publishMenu = () => {
                try {
                    window.__saffronBranchHidden = hiddenOf(branch.value);
                    document.dispatchEvent(new CustomEvent('saffron:branch-menu'));
                } catch (e) { /* the next page load is scoped regardless */ }
            };

            const confirm = () => {
                // Removing happens here and only here — after the customer has read which dishes
                // and pressed the button that says so.
                if (dropping.value.length) {
                    const keep = cartLines().filter(line => servedBy(draftBranch.value, line.id));

                    try {
                        window.OvyntStore.cartList.splice(0, window.OvyntStore.cartList.length, ...keep);
                        window.OvyntStore.saveCart();
                    } catch (e) { /* the guard still refuses server-side */ }
                }

                branch.value = draftBranch.value;
                open.value   = false;

                apply();
            };

            const dismiss = () => {
                // A first visit has nothing to fall back to, so the panel holds. Once a choice
                // exists, closing restores it and discards the draft.
                if (!chosen.value) return;

                draftBranch.value = branch.value;
                open.value        = false;
            };

            watch(open, (isOpen) => {
                if (isOpen) draftBranch.value = branch.value ?? (branches.length ? branches[0].id : null);
            });

            onMounted(() => {
                // The cookie first, because it is what scoped the HTML already on screen; the
                // stored choice only when there is no cookie to read. See `cookieBranch()`.
                const stored = load();
                const chosenId = cookieBranch() ?? (stored ? stored.branch : null);

                // A branch that has since been deleted or deactivated is not offered any
                // more, so the choice is re-asked rather than silently kept — the alternative is
                // scoping a menu to a branch that no longer exists. `branchOf` is the check, and
                // it answers against the branches the server just rendered, so a stale id in
                // either store fails it and the gate re-opens.
                const validBranch = chosenId && branchOf(chosenId) ? Number(chosenId) : null;

                if (validBranch) {
                    branch.value = validBranch;
                    apply();
                } else {
                    open.value = true;
                }

                // Start watching whatever the page has still to render, and scope again once it
                // has finished — both, because the observer catches nodes added after this point
                // and `load` catches the ones already parsed between mount and now.
                watchForDishes();
                window.addEventListener('load', scopeSoon);
                scopeSoon();

                // Core re-renders its summary as the cart validates, which replaces the branch
                // radios — so the seed is re-applied on the same event core uses.
                document.addEventListener('change', (e) => {
                    if (e.target && e.target.name === 'cms-co-pickup') return;
                    seedCartMethod();
                });
            });

            return {
                branches, labels,
                branch, open, chosen, draftBranch,
                branchLabel, stayLabel, costLine,
                dropping, dropMessage,
                confirm, dismiss,
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
