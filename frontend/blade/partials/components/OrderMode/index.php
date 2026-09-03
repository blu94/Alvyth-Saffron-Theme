<?php

namespace Theme\Components;

use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Theme\Backend\Handlers\ShippingMethodOutlet;
use Theme\Backend\Models\Outlet;
use Theme\Backend\Models\TableBooking;
use Theme\Backend\Repositories\ServiceWindowRepository;
use Theme\Backend\Support\BookingWindow;
use Theme\Backend\Support\ThemeSettings;

/**
 * Delivery or pickup — the choice that decides whether an order needs an address.
 *
 * **Why this can exist at all.** Until core gained a fulfilment type, whether checkout demanded
 * an address was decided by `Product.requires_shipping`, a column on the shared catalogue. So a
 * shop had to pick one mode for everything it sold: flag a dish shippable and every collection
 * order was refused for want of an address; flag it not-shippable and no delivery fee could
 * ever be charged. There was no third setting (spec §3, §14 item 3, register O4). The order now
 * names its own type and that wins, so one menu can genuinely be offered both ways.
 *
 * **The control is the theme's, the contract is core's.** Core's Cart section reads
 * `[data-checkout-mode]` from anywhere on the page and posts it as `fulfillment_type` — the
 * same shape as `[data-checkout-field]`, which this theme already uses for the scheduled time.
 * That split is deliberate: how a restaurant presents "deliver it or come and get it" is a
 * presentation decision, and a bookshop offering click-and-collect wants a quieter one.
 *
 * **Renders nothing when there is no choice to make.** A delivery-only or pickup-only shop gets
 * a single hidden input carrying its one mode rather than a picker with one option — the
 * server still learns which it is, and the customer is not asked a question with one answer.
 */
class OrderMode
{
    /**
     * How many held spans the page will carry at most.
     *
     * Far above what a restaurant's online bookings produce over a month — a twenty-table shop
     * turning every table twice a night reaches this only after a fortnight fully booked — and
     * low enough that no shop ever ships a megabyte of occupancy to a cart page. See
     * {@see self::heldSpans()} for what happens past it.
     */
    protected const HELD_LIMIT = 1000;

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = ThemeSettings::all();

        // What the shop offers comes from CORE's shipping methods now, not from a theme
        // setting — the `ordering_modes` select is retired. Pickup is offered when the shop
        // has created pickup-type methods (its branches); delivery per core's rule (always,
        // until pickup methods exist; then only when a delivery-capable zone does). This is
        // what makes the offering identical on every theme: switching themes must never
        // change what a shop sells, only how the choice is drawn.
        //
        // Fails open to delivery-only — the storefront's pre-methods behaviour — rather
        // than taking the cart page down on a service that cannot answer.
        try {
            $shipping = app(\App\Services\Shipping\ShippingService::class);

            $offersPickup   = $shipping->offersPickup();
            $offersDelivery = $shipping->offersDelivery();
        } catch (\Throwable $e) {
            report($e);

            $offersPickup   = false;
            $offersDelivery = true;
        }

        $offered = $offersPickup && $offersDelivery ? 'both' : ($offersPickup ? 'pickup' : 'delivery');

        // The collection address falls back to the footer's, because a shop that filled in one
        // address should not have to fill it in twice to turn pickup on.
        $pickupAddress = trim((string) $this->translate($settings['pickup_address'] ?? '', $locale));

        if ($pickupAddress === '') {
            $pickupAddress = trim((string) $this->translate($settings['footer_address'] ?? '', $locale));
        }

        // ── Which branch to collect from (Q5) ───────────────────────────────────
        // The picker lives HERE, inside the pickup flow, because that is where you said it
        // belongs: "if user choose pick up by themselve, need to show branch option that allow
        // user to pick, but if admin only add 1 outlet, then no need to show outlet options."
        //
        // So one outlet renders no picker at all — its address simply becomes the collection
        // address — which is the same rule this component already applies to a single-mode shop.
        // A shop that has never created an outlet is unchanged in every respect.
        $outlets = $this->collectionOutlets($locale);

        // ── Dine in, and which table (O2) ───────────────────────────────────────
        // A third tile rather than a question buried inside the pickup flow, because that is
        // how a diner thinks about it and what spec §9.2 describes. **Core is still told
        // `pickup`**: a customer sitting in the room needs no address and pays no delivery fee,
        // and `fulfillment_type` only knows delivery and pickup. Which of the two kinds of
        // collection it is rides on `checkout_fields`, the theme's own bag — the same split as
        // everything else here, and the reason this needed no core change.
        //
        // Offered only where a shop actually has a dining room. Most takeaway shops do not, and
        // a tile that leads to "which table?" in a shop with no tables is worse than no tile.
        //
        // **Two switches, and the second is per branch.** The shop-wide one below permits dining
        // at all; `outlets.offers_dine_in` decides whether a given branch seats anybody, and it
        // defaults to off. Collection and delivery have been per branch since outlets existed and
        // dine-in simply rode on collection, so a takeaway kiosk with a counter and no seating was
        // offered *Dine in* while the dining-room repeater beside it was already asking that same
        // branch for its tables.
        //
        // The shop-wide answer decides whether the tile is RENDERED; the branch decides whether it
        // is OFFERED, in the browser, because only the browser knows which branch was chosen at
        // the gate. Same split as `offers_pickup` / `offers_delivery` — see `branchDoors()`.
        $dineIn = $offered !== 'delivery'
            && $this->bool($settings['offer_dine_in'] ?? false)
            && $this->anyBranchDinesIn();

        // A list beats free text when the shop knows its own tables: a diner mistyping 21 for 12
        // sends the food to somebody else's table, and nothing downstream can catch it. Empty
        // means the tables are not numbered 1..N — a courtyard, named booths — so the customer
        // types whatever they are called.
        $tables = (int) ($settings['dine_in_tables'] ?? 0);
        $tables = $tables > 0 ? min($tables, 200) : 0;

        // ── Cutlery (O2) ────────────────────────────────────────────────────────
        // An opt-OUT, which is what the platforms that popularised it settled on: the default
        // stays what the shop already does, and only a customer who says so changes it. Asked
        // for delivery and collection alike — the waste is the same either way — but never for
        // dine-in, where the cutlery is already on the table.
        $askCutlery = $this->bool($settings['ask_cutlery'] ?? false);

        // The choices in the order they are offered. Two or more is a question; one is an
        // answer, and an answer belongs in a hidden input rather than a control the customer
        // cannot change — the rule this component already applied to a single-mode shop, now
        // applied to the dine-in tile as well.
        $modes = array_values(array_filter([
            $offered !== 'pickup'   ? 'delivery' : null,
            $offered !== 'delivery' ? 'pickup'   : null,
            $dineIn                 ? 'dine_in'  : null,
        ]));

        $payload = [
            'offered' => $offered,
            'modes'   => $modes,
            'labels'  => [
                'heading'  => __('How do you want it'),
                'delivery' => __('Delivery'),
                'pickup'   => __('Pickup'),
                'dineIn'   => __('Dine in'),
                'deliveryHint' => __('Brought to your address'),
                'pickupHint'   => __('Collect it from us'),
                'dineInHint'   => __('Eat with us'),
                'collectFrom'  => __('Collect from'),
                'copy'         => __('Copy'),
                'copied'       => __('Copied'),
                'chooseOutlet' => __('Which branch?'),
                'chooseTable'  => __('Which table?'),
                'tablePlaceholder' => __('e.g. 12'),
                'branchFirst'  => __('Choose your branch above, then pick your table'),
                'tableOption'  => __('Table :number'),
                'noCutlery'    => __('I do not need cutlery'),
                'noCutleryHint' => __('Helps us cut down on waste'),
                'party'        => __('How many of you?'),
                'partyOption'  => __(':count people'),
                'partyOne'     => __('Just me'),
                'seatsHint'    => __('seats :count'),
                'noneFree'     => __('No table at that branch is free then. Try another time, or a smaller party.'),
                'notDining'    => __('This branch does not seat diners. Choose another branch above, or switch to Pickup.'),
                'someTaken'    => __('Tables already booked at that time are not listed.'),
                'ready'    => $this->translate($settings['pickup_ready_label'] ?? '', $locale)
                    ?: __('Ready to collect in about 20 minutes'),
            ],
            'pickupAddress' => $pickupAddress,
            'outlets'       => $outlets,
            'dineIn'        => $dineIn,
            // The shop-wide count, kept as the FALLBACK for a branch that has listed no tables
            // of its own — which is every branch until an operator fills the dining room in, so
            // this is also the upgrade path. A shop whose branches differ should stop using it;
            // the setting's own hint says so.
            'tables'        => $tables,
            'methodOutlets' => $this->methodOutlets(),
            // **Which doors each branch actually opens** (register O18a). `offers_pickup` and
            // `offers_delivery` are columns on the outlet, so the tiles offered here must narrow
            // to the branch the customer chose at the gate — otherwise a branch that does not
            // deliver still shows Delivery, and the customer walks into the dead end the Outlets
            // form promises cannot exist.
            //
            // Two different questions, and only the second depends on state the server cannot
            // see: the shop-wide answer above decides which tiles are *rendered at all* (core's
            // shipping configuration, which the server knows), and this decides which are
            // *offered* to the one branch the browser has stored.
            'branchDoors'   => $this->branchDoors(),
            // Whether the shop is a branch shop at all. A shop with no outlets has no branch to
            // narrow to, so the tile stands on the shop-wide switch alone — which is exactly what
            // it did before this column existed, and why a single-site restaurant is untouched.
            'hasBranches'   => $this->hasBranches(),
            'bookings'      => $this->bool($settings['accept_table_bookings'] ?? false),
            'askCutlery'    => $askCutlery,
            // How long a booking at each branch holds its table, so the browser can work out
            // the span a chosen time would occupy and compare it with the ones already held.
            // Resolved HERE rather than in JavaScript because `BookingWindow` is the one place
            // the shop default, the per-branch override and the clamp live — re-deriving that
            // precedence in the browser is how the page and the server come to disagree.
            'bookingMinutes' => $this->bookingMinutes($outlets, $settings),
            // The shop-wide answer, for the moment before a branch is chosen and for a shop
            // that has no branches at all. Passed rather than repeated as a literal in the
            // browser: `BookingWindow::DEFAULT_MINUTES` is the only place 60 is written down.
            'bookingMinutesDefault' => BookingWindow::minutesFor(null, $settings),
            // The tables already spoken for, by branch and by label. A convenience: the page
            // stops offering a table it can see is taken, and `TableReservation` re-reads the
            // overlap behind a row lock at checkout, which is the answer that counts. A booking
            // made in the seconds after this page rendered costs a refusal, never a double
            // booking.
            'held'           => $this->heldSpans($settings),
        ];

        return View::make($themeViewPath, [
            'offered'       => $offered,
            'pickupAddress' => $pickupAddress,
            'payload'       => $payload,
            'askCutlery'    => $askCutlery,
            'modes'         => $modes,
            'tables'        => $tables,
            'bookings'      => $this->bool($settings['accept_table_bookings'] ?? false),
            'uid'           => 'saffron-order-mode',
        ])->render();
    }

    /** A switch setting, read the way every other theme switch is. */
    protected function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * The branches a customer may collect from, default first.
     *
     * Wrapped in a try/catch because `outlets` ships with this theme's migrations: a storefront
     * rendering against a half-deployed import or a stale cache must fall back to no outlets —
     * which is exactly the shop-with-one-address behaviour that already worked — rather than
     * taking the cart page down on a missing table. The same guard `DishSheet` puts round
     * `modifier_groups`, for the same reason.
     *
     * @return array<int,array{id:int,title:string,address:string,phone:string}>
     */
    protected function collectionOutlets(string $locale): array
    {
        try {
            return Outlet::active()
                ->pickup()
                // Default first so the picker opens on the branch the shop nominated, then the
                // operator's own arrangement.
                ->orderByDesc('is_default')
                ->ordered()
                // The dining room travels with the branch. Eager-loaded — BEFORE `get()`, which
                // is where this sat wrongly for one deploy: `with()` on the returned Collection
                // is not a method, the try/catch below swallowed the BadMethodCallException, and
                // the storefront silently fell all the way back to no outlets at all. Loaded
                // rather than fetched when the customer picks a branch, because the whole set is
                // a handful of short strings and a round trip mid-checkout is one that can fail.
                ->with(['tables' => fn ($q) => $q->active()->ordered()])
                ->get()
                ->map(fn (Outlet $outlet) => [
                    'id'      => $outlet->id,
                    'title'   => $this->translate($outlet->title, $locale) ?: $outlet->slug,
                    'address' => trim((string) $outlet->address),
                    'phone'   => trim((string) $outlet->phone),
                    // Labels and seats. The **label** is still what `table_number` posts, exactly
                    // as it has always done — sending ids would change the checkout-field
                    // contract and orphan every order already carrying a label. `seats` rides
                    // alongside because the writer refuses a party larger than the table
                    // (`TableReservation::write()`), and a picker that offers a two-seater to a
                    // party of eight is a refusal the customer meets at the payment button
                    // instead of at the question.
                    'tables'  => $outlet->tables
                        ->map(fn ($table) => [
                            'label' => (string) $table->label,
                            // 0 means "not recorded", which reads as "fits anybody" rather than
                            // as "seats nobody": a shop that never filled the column in must not
                            // have every one of its tables filtered away.
                            'seats' => (int) ($table->seats ?? 0),
                        ])
                        ->values()
                        ->all(),
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Which doors each branch opens, by outlet id.
     *
     * The same query the order gate lists branches from — active, and offering at least one of
     * the two — so the two components cannot disagree about which branches exist or what they
     * do. A branch with neither door is absent from both, which is what makes "left out of the
     * list altogether" true rather than aspirational.
     *
     * Fails open to an empty map, which restores the shop-wide behaviour this had before
     * branches could be chosen: every tile the shop offers is offered everywhere.
     *
     * @return array<int, array{pickup: bool, delivery: bool}>
     */
    protected function branchDoors(): array
    {
        try {
            return Outlet::query()
                ->active()
                ->where(fn ($q) => $q->where('offers_pickup', true)->orWhere('offers_delivery', true))
                ->get(['id', 'offers_pickup', 'offers_delivery', 'offers_dine_in'])
                ->mapWithKeys(fn (Outlet $outlet) => [
                    (int) $outlet->id => [
                        'pickup'   => (bool) $outlet->offers_pickup,
                        'delivery' => (bool) $outlet->offers_delivery,
                        // `dinesIn()` rather than the bare column, so the browser is handed the
                        // composed answer and cannot forget that dining rides on collection. A
                        // branch that stops collecting stops seating on the same save, with no
                        // second rule for the page to remember.
                        'dineIn'   => $outlet->dinesIn(),
                    ],
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Has this shop got any branches at all?
     *
     * Asked separately from {@see self::branchDoors()} because an EMPTY map has two meanings that
     * must not collapse into one: a shop with no outlets (offer whatever the shop offers — the
     * behaviour every single-site restaurant has always had), and a query that failed (same
     * answer, deliberately, because failing open is this feature's rule throughout). Only the
     * first is a fact about the shop, and only the tile-rendering decision may act on it.
     *
     * Fails open to `false`, which keeps the shop-wide switch in charge — a half-deployed import
     * must not be able to take the Dine in tile off a working restaurant.
     */
    protected function hasBranches(): bool
    {
        try {
            return Outlet::query()->active()->exists();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Does any active branch seat diners?
     *
     * Stops the tile being drawn at all for a branch shop where nobody dines in — otherwise, with
     * this column defaulting to off, every existing multi-branch shop would render a Dine in tile
     * that vanishes the moment a branch is chosen. A control that appears and then withdraws reads
     * as a fault; one that was never there reads as a shop that does not do it.
     *
     * **A shop with no outlets answers `true`**, because it has no branch to consult and the
     * shop-wide switch is the whole answer there — the single-site case, unchanged. Failing open
     * to `true` for the same reason: this decides what is offered, and the checkout guard is what
     * actually holds.
     */
    protected function anyBranchDinesIn(): bool
    {
        try {
            if (! Outlet::query()->active()->exists()) {
                return true;
            }

            return Outlet::query()->active()->dineIn()->exists();
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    /**
     * Which outlet each pickup-type shipping method stands for.
     *
     * The branch question is **core's** now — one pickup method per branch — so the only thing
     * the browser learns when a customer picks one is a method id. The link from that to an
     * outlet lives in the method's own `meta.checkout_fields.outlet_id`, written by this
     * theme's `ShippingMethodOutlet` handler and normally read server-side at checkout. The
     * table list has to resolve it *before* the order exists, so the map is handed to the page.
     *
     * Ids only, and nothing else about the method: this is a lookup, not a second branch picker.
     *
     * Delegated to the handler that WRITES the key, so the meta path is spelled once. The
     * schedule block needs the same map — a per-branch booking lead is unresolvable without it —
     * and two components spelling out `meta.checkout_fields.outlet_id` is how one of them keeps
     * the old spelling after the other moves. It fails open there for the reason it does here: a
     * storefront rendering against a half-deployed import falls back to the shop-wide table list
     * rather than taking the cart page down.
     *
     * @return array<int, int> method id => outlet id
     */
    protected function methodOutlets(): array
    {
        return ShippingMethodOutlet::map();
    }

    /**
     * How long a booking holds a table, at each branch the customer can choose.
     *
     * Keyed by outlet id so the browser can look the answer up the moment a branch is picked.
     * Every value comes from {@see BookingWindow}, never from a second reading of the settings
     * array — the branch override, the shop default and the clamp are one rule, and a copy of
     * that rule in JavaScript is a copy that drifts.
     *
     * @param  array<int,array{id:int}>  $outlets
     * @return array<int,int> outlet id => minutes
     */
    protected function bookingMinutes(array $outlets, array $settings): array
    {
        $map = [];

        foreach ($outlets as $outlet) {
            $map[(int) $outlet['id']] = BookingWindow::minutesFor((int) $outlet['id'], $settings);
        }

        return $map;
    }

    /**
     * The spans already held, by branch and by table label.
     *
     * **Why this rides on the page rather than being asked for.** A theme registers no routes —
     * core's storefront API is a fixed list — so the only way the picker can know a table is
     * taken is to be told at render time. That is the same arrangement the schedule block uses
     * for the shop's hours, and it carries the same contract: the page is a convenience and
     * `TableReservation` is the truth.
     *
     * Bounded three ways, because this is a public payload on a page a shop serves to everybody:
     * only bookings still holding a table, only those overlapping the window the schedule picker
     * can actually reach, and never more than {@see self::HELD_LIMIT} rows. A shop busy enough to
     * exceed that keeps a complete list for the nearest dates — the rows are taken in start
     * order — and falls back to a checkout refusal for the far ones, which is the same outcome
     * the picker already relies on for a booking made a second ago.
     *
     * Times are formatted without a timezone conversion on purpose: `TableReservation` writes
     * the shop's own wall clock into these columns, so reading the digits straight back is what
     * keeps the browser comparing like with like.
     *
     * @return array<int, array<string, array<int, array<int, string>>>> outlet id => label => spans
     */
    protected function heldSpans(array $settings): array
    {
        if (! $this->bool($settings['accept_table_bookings'] ?? false)) {
            return [];
        }

        try {
            $timezone = app(ServiceWindowRepository::class)->timezone();
            $horizon  = app(ServiceWindowRepository::class)->schedulingHorizon();

            $now   = Carbon::now($timezone);
            $until = $now->copy()->addDays($horizon);

            $rows = TableBooking::query()
                // Through the scope again, now that it qualifies its own column. It used to be
                // written out here because the scope filtered on a bare `status` and both tables
                // in the join below carry one — an ambiguous clause MySQL refuses outright, which
                // the catch below turned into an empty map and a picker offering every table as
                // free. The scope takes the table name for exactly that reason.
                //
                // Worth calling rather than copying: "still holding" now also means the order has
                // not been cancelled, and a hand-written copy of that rule is a second place for
                // it to drift. The picker and the writer must agree about which tables are free,
                // or the page offers a table checkout then refuses.
                ->holding()
                ->join('outlet_tables', 'outlet_tables.id', '=', 'table_bookings.outlet_table_id')
                ->whereNull('outlet_tables.deleted_at')
                ->where('outlet_tables.status', 'active')
                // A booking that has already finished holds nothing, and one starting past the
                // horizon is one the picker cannot offer a time inside anyway.
                ->where('table_bookings.ends_at', '>', $now->format('Y-m-d H:i:s'))
                ->where('table_bookings.starts_at', '<', $until->format('Y-m-d H:i:s'))
                ->orderBy('table_bookings.starts_at')
                ->limit(self::HELD_LIMIT)
                ->get([
                    'outlet_tables.outlet_id',
                    'outlet_tables.label',
                    'table_bookings.starts_at',
                    'table_bookings.ends_at',
                ]);

            $held = [];

            foreach ($rows as $row) {
                // Lower-cased, because the label is what the customer picks and
                // `TableReservation::tableFor()` matches it case-insensitively. Keying on the
                // raw label would let "Window" and "window" hold the same table twice over.
                $key = mb_strtolower(trim((string) $row->label));

                $held[(int) $row->outlet_id][$key][] = [
                    Carbon::parse($row->starts_at)->format('Y-m-d H:i'),
                    Carbon::parse($row->ends_at)->format('Y-m-d H:i'),
                ];
            }

            return $held;
        } catch (\Throwable $e) {
            // The same failing-open rule the rest of this driver follows: a storefront rendering
            // against a half-deployed import offers every table and lets the writer refuse,
            // rather than taking the cart page down over a convenience.
            report($e);

            return [];
        }
    }

    /** Resolve a translatable setting that may arrive as a raw string or a locale map. */
    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
