<?php

namespace Theme\Backend\Repositories;

use App\Contracts\Notification\Notifier;
use App\Models\Order;
use App\Models\Product;
use App\Repositories\Order\OrderInterface;
use App\Services\Notification\NotificationTypeRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Theme\Backend\Models\Outlet;
use Theme\Backend\Models\ServiceWindow;
use Theme\Backend\Support\ThemeSettings;

/**
 * Resolved by `GenericModuleController` for the `service-windows` module.
 *
 * Beyond ordinary CRUD this repository serves the `kitchen` page — the order queue, as
 * columns of cards by kitchen state — through the `pageData($slug)` / `savePageData($slug,
 * $data)` seam exposed at `GET|POST /admin/modules/service-windows/page/{slug}`.
 *
 * **A `availability` page used to live here too, and has been removed.** It offered a toggle
 * per dish, and that toggle wrote `Product.status` — the only lever available at the time,
 * because nothing in core read the `stock` column. Writing `status` *unpublishes*: marking
 * tonight's special sold out took it off the menu entirely instead of greying it out, which
 * also meant the sold-out badge in `DishCard` could never render. Core now enforces `stock`
 * in `validateCart`, so the dish's own Stock field says "sold out" and the dish stays on the
 * menu where the customer can see it. One control, on the product, doing the right thing.
 */
class ServiceWindowRepository
{
    /**
     * Kitchen states, and how each maps onto Alvyth's three orthogonal status axes.
     *
     * A kitchen thinks in states the generic vocabulary does not name. Resisting a fourth
     * status column is deliberate: `ReportRepository` counts revenue by `payment_status` and
     * excludes cancelled orders, so adding food states to `Order::STATUSES` would change what
     * every existing report means. Where the mapping is genuinely lossy — "ready for
     * collection" versus "with the rider" — the finer state goes in `meta.kitchen_state`,
     * which is what `column` below reads.
     */
    /**
     * How many open orders the queue draws at once.
     *
     * A board nobody can scroll is not a board, and every ticket carries its lines and their
     * options. The cap is on *rendering*, never on what the screen reports: `open_total` and
     * the arrival chime are both counted without it, so a truncated queue says so instead of
     * reading like a short one. See {@see kitchenData()} and register D-15.
     */
    public const QUEUE_LIMIT = 120;

    public const KITCHEN_STATES = [
        'new' => [
            'label'              => 'New',
            'status'             => Order::STATUS_CONFIRMED,
            'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            'next'               => 'preparing',
        ],
        'preparing' => [
            'label'              => 'Preparing',
            'status'             => Order::STATUS_PROCESSING,
            'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            'next'               => 'ready',
        ],
        'ready' => [
            'label'              => 'Ready',
            'status'             => Order::STATUS_PROCESSING,
            'fulfillment_status' => Order::FULFILLMENT_READY,
            'next'               => 'out',
        ],
        'out' => [
            'label'              => 'Out for delivery',
            'status'             => Order::STATUS_PROCESSING,
            'fulfillment_status' => Order::FULFILLMENT_OUT,
            'next'               => 'delivered',
        ],
        'delivered' => [
            'label'              => 'Delivered',
            'status'             => Order::STATUS_COMPLETED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'next'               => null,
        ],
    ];

    /** The Kitchen Queue's branch picker's "do not narrow this board" value. */
    public const BRANCH_ALL = 'all';

    /**
     * Which branch an order names, as SQL — the one expression every branch-aware reader uses.
     *
     * Written once because the board and the count that polices the board must not be able to
     * disagree: if the drawn rows and `open_total` answered this question differently, the
     * screen would report a truncation that is not there, or hide one that is.
     *
     * Three things it has to survive, each measured against this database rather than assumed:
     *
     * - **The id is stored as a JSON *string*.** `JSON_EXTRACT` returns `"7"`, not `7`, because
     *   the value arrives from a `[data-checkout-field]` bag, and every value in that bag is
     *   text by the time it is persisted. Comparing against an integer matches **nothing** —
     *   silently, which would have read as "no orders at this branch".
     * - **The legacy key.** `outletName()` falls back to `meta.outlet_id` for orders written
     *   before the checkout bag carried it, so the filter reads both or an order the ticket
     *   labels *Bangsar* would be treated as belonging to no branch at all.
     * - **A JSON `null` unquotes to the four-character string `'null'`**, and an empty string
     *   is not an answer either. Both collapse to SQL `NULL`, which is how this expression
     *   spells "this order names no branch".
     */
    protected const BRANCH_SQL =
        "NULLIF(NULLIF(COALESCE("
        . "JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.checkout_fields.outlet_id')), "
        // The branch a delivery order was *routed* to, written by
        // `Writers\DeliveryKitchenRouting`. Read second, so a branch the customer actually
        // chose always wins — the two keys are different facts and only one of them is an
        // answer the customer gave.
        . "JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.checkout_fields.kitchen_outlet_id')), "
        . "JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.outlet_id'))"
        . "), 'null'), '')";

    // ── CRUD ────────────────────────────────────────────────────────────────────

    public function baseIndexQuery(array $filters = [])
    {
        $query = ServiceWindow::query();

        if (!empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['day_of_week']) && $filters['day_of_week'] !== '') {
            $query->where('day_of_week', (int) $filters['day_of_week']);
        }

        if (!empty($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }

        // 'shop' is the whole-shop rows alone; a number is one branch's own rows. Distinct
        // values because "no filter" must keep showing everything, the way it always has.
        if (!empty($filters['outlet'])) {
            $filters['outlet'] === 'shop'
                ? $query->forShop()
                : $query->forOutlet((int) $filters['outlet']);
        }

        // Weekly rows first and in week order, then the dated overrides in date order. Both
        // sort keys are null for the other kind, so a single `orderBy` pair would interleave
        // holidays through the week at whatever position null happens to sort.
        return $query
            ->with('scope')
            ->orderBy('kind')
            ->orderByRaw('day_of_week IS NULL, day_of_week')
            ->orderByRaw('date IS NULL, date')
            ->orderBy('opens_at');
    }

    public function find($id)
    {
        $window = ServiceWindow::find($id);

        // The form speaks in `outlet_id`; the row stores a polymorphic scope. Hydrated here so
        // the Branch autocomplete opens holding the branch the row belongs to.
        if ($window && $window->scope_type === Outlet::class) {
            $window->setAttribute('outlet_id', (int) $window->scope_id);
        }

        return $window;
    }

    public function create(array $data)
    {
        return ServiceWindow::create($this->normalise($data));
    }

    public function update($id, array $data)
    {
        $window = ServiceWindow::findOrFail($id);
        $window->update($this->normalise($data));

        return $window;
    }

    public function delete($id)
    {
        return ServiceWindow::findOrFail($id)->delete();
    }

    /**
     * Filter and select options.
     *
     * `open_order` and `kitchen_state` are virtual: they are not columns on this model at
     * all. They exist because a module page schema can populate a select from `options.url`
     * but has no other way to reach live records, and the kitchen queue needs to name an
     * order. Serving them here keeps that page fully schema-driven instead of bypassing the
     * engine.
     *
     * A `menu_dish` source sat alongside them, feeding the removed availability page's dish
     * picker. It went with the page: the dish's own Stock field is where availability is set
     * now, so nothing needs a second list of dishes to point at.
     */
    /**
     * `$filters` is the request's other query parameters, and declaring it is how this
     * repository opts in to receiving them — core checks the signature, not a list of names.
     *
     * It carries `branch_id` for the Kitchen Queue's *Advance An Order* list, posted by the
     * schema engine's `options.dependsOn` from the Branch picker on the same page. Same
     * user-input rules as `pageData()`: resolved through `branchFilter()`, never trusted.
     */
    public function getOptions(array $columns = [], array $filters = [])
    {
        $options = [];

        // A schema asks for a column with `params: {"columns[]": "…"}`; a single value can
        // arrive as a bare string, and iterating a string is a TypeError, not an empty list.
        foreach ((array) $columns as $column) {
            $options[$column] = match ($column) {
                'status', 'mode' => ServiceWindow::query()
                    ->whereNotNull($column)
                    ->distinct()
                    ->pluck($column)
                    ->map(fn ($v) => ['label' => Str::headline((string) $v), 'value' => $v])
                    ->values()
                    ->all(),

                'day_of_week' => collect(ServiceWindow::DAYS)
                    ->map(fn ($label, $value) => ['label' => $label, 'value' => (string) $value])
                    ->values()
                    ->all(),

                'kind' => [
                    ['label' => 'Weekly hours', 'value' => ServiceWindow::KIND_RECURRING],
                    ['label' => 'Holiday or closure', 'value' => ServiceWindow::KIND_EXCEPTION],
                ],

                // The list's Branch filter. Every branch, active or not — a deactivated
                // branch's rows still exist and still need finding, and the outlets screen is
                // where its status lives.
                'outlet' => collect([['label' => __('Whole shop'), 'value' => 'shop']])
                    ->concat(
                        Outlet::query()->orderBy('id')->get()->map(fn (Outlet $outlet) => [
                            'label' => trim((string) (
                                $outlet->getTranslation('title', app()->getLocale(), false)
                                    ?: $outlet->getTranslation('title', 'en', false)
                            )) ?: (string) $outlet->slug,
                            'value' => (string) $outlet->id,
                        ])
                    )
                    ->values()
                    ->all(),

                // The Kitchen Queue's branch picker. **Deliberately not the `outlet` column
                // above**, though the two look identical apart from their first row, and that
                // first row is the whole reason they are separate: on Hours & Holidays
                // *Whole shop* means "entries scoped to no branch", a real and narrow answer,
                // while here *All branches* means "do not narrow this board at all". Sharing
                // one column would make one of the two screens lie about what it is offering.
                //
                // Every branch, active or not, for the same reason the other list takes them
                // all: a branch deactivated at lunchtime still has tickets on the pass.
                'kitchen_branch' => collect([['label' => __('All branches'), 'value' => self::BRANCH_ALL]])
                    ->concat(
                        Outlet::query()->orderBy('id')->get()->map(fn (Outlet $outlet) => [
                            'label' => trim((string) (
                                $outlet->getTranslation('title', app()->getLocale(), false)
                                    ?: $outlet->getTranslation('title', 'en', false)
                            )) ?: (string) $outlet->slug,
                            'value' => (string) $outlet->id,
                        ])
                    )
                    ->values()
                    ->all(),

                'kitchen_state' => collect(self::KITCHEN_STATES)
                    ->map(fn ($state, $key) => ['label' => $state['label'], 'value' => $key])
                    ->values()
                    ->all(),

                // **Narrowed by the same branch the board above it is showing.** It was not,
                // and that was a real hole rather than a cosmetic one: a counter scoped to
                // Bangsar still had every branch's orders in this select, so the screen that
                // exists to stop somebody reading another branch's ticket would happily let
                // them advance one. The branch arrives from the Branch picker through
                // `options.dependsOn`, and is resolved by the same `branchFilter()` the board
                // uses — one resolver, so the list and the board cannot disagree about which
                // branch is meant.
                //
                // Orders naming no branch stay in the list for the reason they stay on the
                // board: nothing records which branch cooks a delivery, so they are everyone's.
                'open_order' => Order::query()
                    ->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING])
                    ->when(
                        $this->branchFilter($filters),
                        fn ($query, $branchId) => $query->where(fn ($q) => $q
                            ->whereRaw(self::BRANCH_SQL . ' = ?', [(string) $branchId])
                            ->orWhereRaw(self::BRANCH_SQL . ' IS NULL'))
                    )
                    // **Newest first, then re-sorted for reading.** The cap has to fall on the
                    // OLDEST orders, not the newest: taking the first 200 to arrive is how a
                    // saturated shop ends up unable to select the ticket that just came in —
                    // the same inversion the board itself was cured of. The list is handed back
                    // oldest-first, because a counter works down from the longest wait.
                    ->orderByDesc('created_at')
                    ->limit(200)
                    ->get(['id', 'order_number', 'grand_total', 'created_at'])
                    ->sortBy('created_at')
                    ->values()
                    ->map(fn ($o) => [
                        'label' => sprintf(
                            '%s — waiting %s',
                            $o->order_number,
                            $this->waitLabel((int) (optional($o->created_at)->diffInMinutes(now()) ?? 0))
                        ),
                        'value' => (string) $o->id,
                    ])
                    ->values()
                    ->all(),


                default => [],
            };
        }

        return $options;
    }

    protected function normalise(array $data): array
    {
        $out = collect($data)
            ->only([
                'kind', 'day_of_week', 'date',
                'opens_at', 'closes_at', 'mode', 'exception_type', 'reason',
                'status', 'orders', 'data',
            ])
            ->all();

        $kind = in_array($out['kind'] ?? null, [ServiceWindow::KIND_RECURRING, ServiceWindow::KIND_EXCEPTION], true)
            ? $out['kind']
            : ServiceWindow::KIND_RECURRING;

        $out['kind'] = $kind;

        foreach (['opens_at', 'closes_at'] as $key) {
            if (!empty($out[$key])) {
                $out[$key] = substr((string) $out[$key], 0, 5) . ':00';
            }
        }

        // The form hides the other kind's fields rather than removing them from the payload,
        // so a row switched from one kind to the other would otherwise keep the values it was
        // showing a moment ago — a weekday on a dated holiday, or a date on a Tuesday window.
        // Clearing here means the row on disk only ever carries the half its kind uses, and
        // every reader can trust `kind` alone.
        if ($kind === ServiceWindow::KIND_EXCEPTION) {
            $out['day_of_week'] = null;

            $out['exception_type'] = in_array($out['exception_type'] ?? null, ['closed', 'open', 'custom'], true)
                ? $out['exception_type']
                : 'closed';

            // A closed day has no hours. Keeping them would render "Closed, 12:00–16:00".
            if ($out['exception_type'] === 'closed') {
                $out['opens_at']  = null;
                $out['closes_at'] = null;
            }

            // A dated override closes or opens the shop however it is being served.
            $out['mode'] = 'both';
        } else {
            $out['date']           = null;
            $out['exception_type'] = null;
            $out['reason']         = null;

            if (array_key_exists('day_of_week', $out)) {
                $out['day_of_week'] = max(0, min(6, (int) $out['day_of_week']));
            }
        }

        // The form speaks in `outlet_id`; the row stores a polymorphic scope. The pair is
        // derived here and ONLY here — `scope_type` and `scope_id` are no longer accepted from
        // the payload at all, so a hand-crafted request cannot point the morph at an arbitrary
        // class. An id naming no outlet collapses to the whole shop rather than saving a
        // dangling reference: a stale option in a form left open while a branch was deleted
        // must not scope hours to a place that is gone.
        $outletId = (int) ($data['outlet_id'] ?? 0);

        if ($outletId > 0 && Outlet::query()->whereKey($outletId)->exists()) {
            $out['scope_type'] = Outlet::class;
            $out['scope_id']   = $outletId;
        } else {
            $out['scope_type'] = null;
            $out['scope_id']   = null;
        }

        return $out;
    }

    // ── Custom admin pages ──────────────────────────────────────────────────────

    /**
     * `$filters` is the page's own query string, handed over by `GenericModuleController`.
     *
     * It is **user input** — anyone who can open the Kitchen Queue can craft it — so the
     * branch is resolved through {@see branchFilter()} rather than trusted, and the second
     * argument is defaulted so a shop on a core that predates the capability still renders an
     * unscoped board instead of erroring.
     */
    public function pageData(string $slug, array $filters = [])
    {
        return match ($slug) {
            'kitchen' => $this->kitchenData($this->branchFilter($filters)),
            default   => [],
        };
    }

    /**
     * The branch the counter has picked, or `null` for "every branch".
     *
     * `null` is the answer to every way of not choosing — the picker's own *All branches* row,
     * an absent parameter, an empty one, a zero, a word, or an id naming a branch that has
     * since been deleted. That last is the one worth spelling out: a tablet left open on a
     * branch somebody closed at head office must widen to the whole shop rather than show an
     * empty board, because an empty board and a quiet night look identical to a counter.
     */
    protected function branchFilter(array $filters): ?int
    {
        $raw = $filters['branch_id'] ?? null;

        if ($raw === null || $raw === '' || $raw === self::BRANCH_ALL) {
            return null;
        }

        $id = (int) $raw;

        if ($id <= 0) {
            return null;
        }

        try {
            // `withTrashed()`, matching `outletName()`: a branch soft-deleted mid-service still
            // has tickets on the pass, and they are still somebody's job to cook.
            return Outlet::withTrashed()->whereKey($id)->exists() ? $id : null;
        } catch (\Throwable $e) {
            // The table ships with this theme's migrations. A queue rendering against a
            // half-deployed import shows every branch rather than nothing at all.
            report($e);

            return null;
        }
    }

    public function savePageData(string $slug, array $data)
    {
        return match ($slug) {
            'kitchen' => $this->advanceKitchenOrder($data),
            default   => ['message' => 'No save handler defined for this page.'],
        };
    }

    /**
     * The queue, as four columns of tickets plus the forward book.
     *
     * Four, not the spec's five: Delivered leaves the queue rather than occupying a column.
     * Refreshed by polling, not websockets — there is no Node in production, no
     * `config/broadcasting.php` at all, and `.env.example` ships `BROADCAST_CONNECTION=log`.
     * `poll_seconds` is what core's module page reads to re-fetch this payload on an interval,
     * and `alert` (see `kitchenAlert()`) is what makes an arrival heard rather than merely
     * drawn. Both are declarative: this repository states what it wants and core does it,
     * because a theme may not ship admin Vue.
     *
     * ## Scoped to one branch — and what that deliberately does not do
     *
     * `$branchId` is the counter's own branch, from the page's Branch picker (`page_filters`,
     * the third page capability beside `poll_seconds` and `alert`). A shop that never touches
     * it, and every single-branch shop, gets exactly the board it had before this existed.
     *
     * **A scoped board still shows every order that names no branch, and that is the design
     * rather than a leak.** Measured on this database before any of it was written: of 498
     * open orders, all 332 collection and dine-in orders carry
     * `checkout_fields.outlet_id`, and **none of the 166 delivery orders do** — not one, and
     * not because they are old, since every recent delivery order carries a checkout bag with
     * no branch in it. The id reaches an order from the *pickup method's* meta, and a delivery
     * order chooses a delivery method, which has no outlet.
     *
     * So **nothing in this system records which branch cooks a delivery**. Filtering them out
     * would have taken a third of the board from every counter with nobody left responsible
     * for it — the exact failure `queue_notice` exists to prevent, because a board missing its
     * deliveries reads precisely like a quiet night. They stay on every branch's board, and
     * `scope_notice` says so in words rather than leaving a counter to work it out.
     *
     * Closing that honestly means routing a delivery order to a branch at checkout, which is a
     * decision about who cooks what and not something a queue may invent for itself.
     */
    protected function kitchenData(?int $branchId = null): array
    {
        $open = Order::query()
            ->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING]);

        // **The scope goes in the QUERY, above the cap — never over the rows it returns.**
        //
        // This is D-15 wearing a different hat. The cap takes the newest 120 open orders; a
        // shop holding 498 across three branches would hand this a slice that is mostly other
        // branches' work, and filtering *afterwards* would leave a counter looking at a
        // handful of its own tickets while believing it could see 120. The board would thin
        // out precisely as the shop got busier, which is when it is trusted most.
        //
        // Applied to `$open` itself, before either clone below, so the drawn rows and
        // `open_total` are counted against the same population — see BRANCH_SQL.
        if ($branchId !== null) {
            $open->where(function ($query) use ($branchId) {
                $query
                    ->whereRaw(self::BRANCH_SQL . ' = ?', [(string) $branchId])
                    ->orWhereRaw(self::BRANCH_SQL . ' IS NULL');
            });
        }

        // **The cap takes the NEWEST, and it used to take the oldest** — register D-15.
        //
        // `orderBy('created_at')->limit(120)` reads as "the first 120 to arrive", which is the
        // wrong 120 by definition: an arrival is the newest order there is, so once a shop was
        // holding 120 open orders **a new one never appeared on the counter's screen at all**.
        // Found by accident — a probe order created for an unrelated check simply was not
        // there, and this dev database happens to sit on exactly 120 stale open orders.
        //
        // Newest-first for the cut, then re-sorted oldest-first for display, because the board
        // reads as a queue: the ticket that has waited longest is the one to cook next.
        $orders = (clone $open)
            // The relation is `customer`, not `user` — Order has a `user_id` column but names
            // the belongsTo after the role, and a guest order has none.
            ->with(['items', 'customer'])
            ->orderByDesc('created_at')
            ->limit(self::QUEUE_LIMIT)
            ->get()
            ->sortBy('created_at')
            ->values();

        // What the cap hid, counted rather than guessed. One extra scalar, and only it can say
        // "this screen is not showing you everything" — a silently truncated queue reads
        // exactly like a short one.
        $openTotal = (clone $open)->count();

        $columns = [];
        foreach (self::KITCHEN_STATES as $key => $state) {
            if ($key === 'delivered') {
                continue;
            }
            $columns[$key] = ['key' => $key, 'label' => $state['label'], 'orders' => []];
        }

        // Orders wanted on a later date, kept out of the live columns entirely.
        //
        // The queue is what the counter works from *now*: it sorts by how long each ticket has
        // waited, and a party booked for November would sit at the top of New for three months,
        // ageing, ahead of the lunch that actually needs cooking. Scheduling reaches ninety days
        // out, so this is not a corner case — it is the ordinary consequence of offering dates.
        // They are listed separately, by the day they are for, which is the only order a
        // forward book reads in.
        $upcoming = [];

        foreach ($orders as $order) {
            $key = $this->kitchenStateFor($order);

            if ($key === null || !isset($columns[$key])) {
                continue;
            }

            $wantedOn = $this->scheduledDate($order);

            if ($wantedOn !== null && $wantedOn > Carbon::now($this->timezone())->toDateString()) {
                $upcoming[$wantedOn][] = $order;

                continue;
            }

            $columns[$key]['orders'][] = [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'placed_at'    => optional($order->created_at)->toDateTimeString(),
                // Carbon 3 returns a float here; the label and the chart both want whole minutes.
                'waiting_mins' => (int) (optional($order->created_at)->diffInMinutes(now()) ?? 0),
                'customer'     => $order->customer?->name ?? 'Guest',
                // `checkout_fields` is where core persists the cart page's
                // [data-checkout-field] values (spec §14 item 2); the bare keys are kept as
                // a fallback for orders written before that landed. An empty string is the
                // picker's own spelling of ASAP, so it collapses to null here.
                // The order's own fulfilment type, which core records from the cart's mode
                // picker (register O4). Read, not inferred: the fallback below guessed from
                // whether an address happened to be attached, which is right for most
                // restaurant orders and wrong for a digital line that needs no address — and
                // guessing is exactly what the column was added to stop. The old meta keys and
                // the guess are kept for orders written before the column existed.
                'mode'         => $order->fulfillment_type
                    ?? $order->meta['checkout_fields']['ordering_mode']
                    ?? $order->meta['ordering_mode']
                    ?? ($order->shipping_address_id ? 'delivery' : 'pickup'),
                'scheduled_at' => ($order->meta['checkout_fields']['scheduled_at'] ?? $order->meta['scheduled_at'] ?? null) ?: null,
                // Dine in, and which table (O2). Core is told `pickup` for both kinds of
                // collection — a diner needs no address and pays no delivery fee, and
                // `fulfillment_type` knows only delivery and pickup — so the ticket is the one
                // place that has to tell them apart. Without this a waiter would be sent to find
                // a customer who took their food home.
                'dining'       => ($order->meta['checkout_fields']['dining'] ?? null) === 'dine_in',
                'table'        => trim((string) ($order->meta['checkout_fields']['table_number'] ?? '')) ?: null,
                // An opt-out, so its presence IS the answer: core's collector skips an unchecked
                // box, and a shop that never asks the question never sees the line.
                'no_cutlery'   => ! empty($order->meta['checkout_fields']['no_cutlery']),
                // Which branch is making it (Q5). Rides in on the same `checkout_fields` bag as
                // the scheduled time, because the cart's picker posts it as a
                // `[data-checkout-field]` — so no core change was needed to persist it, and none
                // is needed to read it here.
                //
                // Resolved to the branch's NAME, not left as an id: a counter reading a ticket
                // needs to know it says Bangsar, and an id would send them to another screen to
                // find out. Null on every order placed before outlets existed, and on every
                // single-outlet shop that never posted one — the ticket simply omits the line
                // rather than printing a blank label.
                'outlet'       => $this->outletName($order),
                'note'         => $order->notes,
                'total'        => $order->grand_total,
                'payment'      => $order->payment_status,
                'state'        => $key,
                'next_state'   => self::KITCHEN_STATES[$key]['next'],
                'items'        => $order->items->map(fn ($item) => [
                    // OrderItem.title is cast to array — it is a snapshot of the translatable
                    // product title taken at checkout, not a plain string.
                    'title'   => $this->lineTitle($item->title),
                    'qty'     => (int) ($item->qty ?? 1),
                    // Spelled out for the kitchen: this is the whole point of the options seam.
                    'options' => $this->flattenOptions($item->meta['options'] ?? []),
                ])->values()->all(),
            ];
        }

        // Flat scalars alongside the nested columns.
        //
        // The schema engine's `display` field renders a string; it has no board or card-list
        // type, so the admin page reads these while `columns` stays available for whatever
        // renders the queue properly later. See ISSUES-SAFFRON-THEME.md O15.
        ksort($upcoming);

        $flat = [
            'columns'      => array_values($columns),
            // The live queue's own count, which is what the tile beside it means. Orders held
            // for a later date are deliberately not in it: a counter reading "12 open" needs
            // that to be twelve things to cook, not nine plus a wedding in October.
            'total_open'   => (string) collect($columns)->sum(fn ($c) => count($c['orders'])),
            'upcoming_count' => (string) collect($upcoming)->sum(fn ($o) => count($o)),
            'upcoming'     => $this->upcomingSummary($upcoming),
            'generated_at' => now()->toDateTimeString(),
            'poll_seconds' => 10,
            // The page's own scope, declared to core's page-filter capability. Core sends
            // `branch_id` back on the first load and on every poll, and remembers it for this
            // device; it never learns that the value names a branch.
            'page_filters' => ['branch_id'],
            'branch_id'    => $branchId === null ? self::BRANCH_ALL : (string) $branchId,
            'scope_notice' => $this->scopeNotice($branchId, $columns),
            // Every open order there is, counted without the render cap. `total_open` is what
            // the board is *showing*; when they disagree the screen has to say so, or a
            // counter reads a truncated queue as a finished one.
            'open_total'   => (string) $openTotal,
            'queue_notice' => $openTotal > self::QUEUE_LIMIT
                ? sprintf(
                    'Showing the %d most recent of %d open orders. %d older %s not on this board — clear them from Sales → Orders.',
                    self::QUEUE_LIMIT,
                    $openTotal,
                    $openTotal - self::QUEUE_LIMIT,
                    $openTotal - self::QUEUE_LIMIT === 1 ? 'order is' : 'orders are'
                )
                : '',
        ];

        foreach ($columns as $key => $column) {
            $flat['count_' . $key] = (string) count($column['orders']);
        }

        $byWait = collect($columns)
            ->flatMap(fn ($c) => $c['orders'])
            ->sortByDesc('waiting_mins')
            ->values();

        $oldest = $byWait->first();

        $flat['oldest'] = $oldest
            ? sprintf('%s — %s (%s)', $oldest['order_number'], $this->waitLabel($oldest['waiting_mins']), self::KITCHEN_STATES[$oldest['state']]['label'])
            : 'Nothing waiting.';

        // One readable ticket per order, options spelled out, because reading the options is
        // the entire point of the queue.
        $flat['queue_summary'] = $byWait
            ->take(30)
            ->map(fn ($o) => $this->ticket($o, true))
            ->implode("\n\n") ?: 'The queue is empty.';

        // The same tickets, one block of text per kitchen state — the spec's board of
        // columns, drawn with the `display` type (which preserves line breaks) because the
        // schema engine has no card-list widget. Each column is oldest first, like the board.
        foreach ($columns as $key => $column) {
            $flat['queue_' . $key] = collect($column['orders'])
                ->sortByDesc('waiting_mins')
                ->take(20)
                ->map(fn ($o) => $this->ticket($o, false))
                ->implode("\n\n") ?: 'Nothing here.';
        }

        // Two charts for the schema engine's `chart` field: the four counts as a donut, and
        // the longest-waiting orders as a horizontal bar so the counter sees at a glance who
        // has waited longest, not just that someone has. `{labels, series}` is the contract
        // BuilderFieldChart reads.
        $flat['orders_by_state'] = [
            'labels' => collect($columns)->pluck('label')->values()->all(),
            'series' => collect($columns)->map(fn ($c) => count($c['orders']))->values()->all(),
        ];

        $longest = $byWait->take(8);

        $flat['waiting_chart'] = [
            'labels' => $longest->pluck('order_number')->values()->all(),
            'series' => [[
                'name' => 'Waiting (min)',
                'data' => $longest->pluck('waiting_mins')->map(fn ($m) => (int) $m)->values()->all(),
            ]],
        ];

        // The queue as a real board, for core's `board` field type (register O15). The text
        // columns above stay: they are what a shop on an older core still renders, and they
        // cost nothing. `columns` was already this shape — only the renderer was missing.
        $flat['board'] = [
            'columns' => collect($columns)->map(fn ($column) => [
                'key'   => $column['key'],
                'label' => $column['label'],
                'cards' => collect($column['orders'])
                    ->sortByDesc('waiting_mins')
                    ->take(20)
                    ->map(fn ($o) => [
                        'id'    => $o['id'],
                        'title' => $o['order_number'],
                        'badge' => $this->waitLabel($o['waiting_mins']),
                        'meta'  => array_values(array_filter([
                            strtoupper((string) $o['mode']),
                            $this->scheduleLabel($o['scheduled_at']),
                        ])),
                        'lines' => collect($o['items'])->map(fn ($item) => [
                            'title'   => $item['title'],
                            'qty'     => $item['qty'],
                            'options' => $item['options'],
                        ])->values()->all(),
                        'note'  => $o['note'] ? __('Note') . ': ' . $o['note'] : null,
                    ])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];

        return $flat + $this->kitchenAlert($byWait, (int) $flat['count_new']);
    }

    /**
     * What this board is showing, in a sentence, whenever that is not simply "everything".
     *
     * The counterpart to `queue_notice`, and it exists for the same reason: a board that is
     * narrower than it looks is indistinguishable from a slow night, and the only cure is for
     * the screen to say so. `queue_notice` covers orders the *cap* dropped; this covers orders
     * the *scope* did — and, more importantly, the ones it deliberately did not.
     *
     * The unassigned figure is the part a counter has to be told rather than left to infer. A
     * branch-scoped board still carries every delivery order in the shop, because nothing
     * records which branch cooks one; without a sentence saying that, a Bangsar counter
     * reasonably reads its board as "Bangsar's work" and either cooks another branch's
     * delivery or assumes somebody else has.
     */
    protected function scopeNotice(?int $branchId, array $columns): string
    {
        // An empty string renders as a bare "-" in a `display` field, which is the right answer
        // for a notice that only sometimes applies (`queue_notice`) and the wrong one here: this
        // field's whole job is to say what the board is showing, and "-" says nothing while
        // looking like a value that failed to load. So the unscoped case gets a sentence too,
        // and it is the sentence that tells an operator the control above exists.
        if ($branchId === null) {
            return 'Showing every branch. Pick one above to narrow this board to a single counter — this device will remember the choice.';
        }

        $drawn = collect($columns)->flatMap(fn ($column) => $column['orders']);

        $unassigned = $drawn->filter(fn ($order) => $order['outlet'] === null)->count();

        $branch = $this->outletLabel($branchId);

        if ($unassigned === 0) {
            return sprintf(
                'Showing %s only. Orders that name no branch would also appear here; there are none right now.',
                $branch
            );
        }

        return sprintf(
            'Showing %s, plus %d %s that %s no branch — nothing records which branch cooks a delivery, so %s on every branch\'s board. Switch to All branches to see the whole shop.',
            $branch,
            $unassigned,
            $unassigned === 1 ? 'order' : 'orders',
            $unassigned === 1 ? 'names' : 'name',
            $unassigned === 1 ? 'it appears' : 'they appear'
        );
    }

    /**
     * One branch's display name, by id. Shares `outletName()`'s memo so a poll that has
     * already resolved the branch on a ticket does not ask the database twice.
     */
    protected function outletLabel(int $id): string
    {
        if (isset($this->outletNames[$id]) && $this->outletNames[$id] !== '') {
            return $this->outletNames[$id];
        }

        try {
            $outlet = Outlet::withTrashed()->find($id);

            $name = $outlet
                ? ($outlet->getTranslation('title', app()->getLocale(), false) ?: $outlet->slug)
                : '';
        } catch (\Throwable $e) {
            report($e);
            $name = '';
        }

        $this->outletNames[$id] = $name;

        // A branch with no readable name still has to be named in the sentence, or the notice
        // reads "Showing , plus 3 orders" — worse than the id it was hiding.
        return $name !== '' ? $name : ('branch #' . $id);
    }

    /**
     * The signal core listens to, and the words it says.
     *
     * Core's page-alert seam (`App\…` side: `usePageAlert`) watches one number in this payload
     * and announces it going up. Which number matters more than it looks:
     *
     * - **Not `count_new`.** A counter who moves one order to Preparing in the same ten seconds
     *   another arrives leaves that count exactly where it was, and the arrival passes in
     *   silence — the one moment the kitchen most needs telling.
     * - **The highest order id in the live columns.** It rises when something newer joins the
     *   queue and cannot be moved by advancing a ticket. It *can* fall, when the newest order is
     *   delivered and leaves; a fall never announces, and the next arrival outranks the lower
     *   mark anyway, so nothing is lost.
     *
     * Orders booked for a later date are excluded, for the same reason `total_open` excludes
     * them: the kitchen bell means "cook this now", and a wedding in October is not that. It
     * rings on the morning the booking joins the queue, which is when it becomes today's work.
     *
     * **This reads the drawn rows, and that is only safe because the cap takes the newest.**
     * Under D-15's `orderBy('created_at')->limit(120)` it was not: a saturated queue bounded
     * this number, so it could not rise however many orders arrived and the screen whose whole
     * purpose is announcing an arrival went silent exactly when the kitchen was busiest. The
     * fix belongs in the query, not here — taking an uncapped `MAX(id)` instead would also
     * work, and would quietly drop the forward-booking exclusion above, which is a decision
     * rather than an implementation detail. `KitchenQueueCapTest` pins the behaviour so the
     * two cannot drift back apart.
     *
     * The wording is rebuilt on every poll, so the message carries the live figure rather than
     * core inventing one — core cannot phrase a queue it does not know it is looking at.
     */
    protected function kitchenAlert(Collection $live, int $newCount): array
    {
        if (! ThemeSettings::bool('kitchen_alert_enabled', true)) {
            return [];
        }

        return [
            'newest_order_id' => (int) ($live->max('id') ?? 0),
            'alert' => [
                'watch'  => 'newest_order_id',
                'label'  => 'New order',
                'text'   => $newCount === 1
                    ? '1 order waiting in New.'
                    : sprintf('%d orders waiting in New.', $newCount),
                // 0 is a real choice for a quiet dining room: the message still appears on
                // screen, the room stays silent.
                'repeat' => min(10, max(0, (int) ThemeSettings::get('kitchen_alert_repeat', 2))),
            ],
        ];
    }

    /**
     * One kitchen ticket as text: header line, then each item with its options in brackets,
     * then the customer's note. `$withState` prints the state on the header, which the
     * per-state columns leave out because the column already says it.
     */
    protected function ticket(array $o, bool $withState): string
    {
        $lines = collect($o['items'])->map(function ($item) {
            $opts = collect($item['options'])
                ->map(fn ($opt) => trim(($opt['label'] ? $opt['label'] . ': ' : '') . $opt['value']))
                ->implode(' · ');

            return '   ' . $item['qty'] . '× ' . $item['title'] . ($opts !== '' ? ' [' . $opts . ']' : '');
        })->implode("\n");

        // DINE IN rather than PICKUP where the customer is eating in the room. Both are `pickup`
        // to core, and the counter's whole question when a ticket comes up is whether to bag it
        // or plate it.
        $header = [
            $o['order_number'],
            ! empty($o['dining']) ? 'DINE IN' : strtoupper((string) $o['mode']),
        ];

        if (! empty($o['dining']) && ! empty($o['table'])) {
            $header[] = 'Table ' . $o['table'];
        }

        if ($withState) {
            $header[] = self::KITCHEN_STATES[$o['state']]['label'] ?? $o['state'];
        }

        $header[] = $this->waitLabel($o['waiting_mins']);
        $header[] = $this->scheduleLabel($o['scheduled_at'] ?? null);

        // Only when the shop actually runs branches. A single-outlet kitchen already knows which
        // kitchen it is, and printing the same name on every ticket costs a line of a screen the
        // counter reads at arm's length.
        if (! empty($o['outlet'])) {
            $header[] = '@ ' . $o['outlet'];
        }

        // Last in the header, because it is an instruction to whoever bags the order rather than
        // a fact about the order. Only ever present when the customer said so.
        if (! empty($o['no_cutlery'])) {
            $header[] = 'NO CUTLERY';
        }

        return implode(' · ', $header) . "\n" . $lines . ($o['note'] ? "\n   Note: " . $o['note'] : '');
    }

    /**
     * The name of the branch an order was placed for, or null.
     *
     * Null covers three ordinary cases, and none of them is an error: an order placed before
     * outlets existed, a single-outlet shop whose picker posted nothing, and an id naming a
     * branch that has since been deleted. The ticket omits the line in all three.
     *
     * Resolved one order at a time on purpose — the queue holds a few dozen tickets, the cache
     * below collapses a shift's worth of orders onto the same handful of branches, and a shop
     * with one outlet never reaches the query at all.
     */
    protected function outletName($order): ?string
    {
        // The branch the customer chose, then the branch a delivery order was routed to, then
        // the legacy key. In that order: a chosen branch is an answer somebody gave, a routed
        // one is an answer the shop worked out, and the ticket should prefer the former.
        $id = $order->meta['checkout_fields']['outlet_id']
            ?? $order->meta['checkout_fields']['kitchen_outlet_id']
            ?? $order->meta['outlet_id']
            ?? null;

        if (! $id) {
            return null;
        }

        $id = (int) $id;

        if (isset($this->outletNames[$id])) {
            return $this->outletNames[$id] ?: null;
        }

        try {
            $outlet = \Theme\Backend\Models\Outlet::withTrashed()->find($id);

            // A deleted branch still names itself on the tickets it took, which is the whole
            // reason `delete()` on this model is a soft delete.
            $name = $outlet
                ? ($outlet->getTranslation('title', app()->getLocale(), false) ?: $outlet->slug)
                : '';
        } catch (\Throwable $e) {
            // The table ships with this theme's migrations; a queue rendering against a
            // half-deployed import shows tickets without a branch rather than not at all.
            report($e);
            $name = '';
        }

        $this->outletNames[$id] = $name;

        return $name ?: null;
    }

    /** Branch names already looked up while building this payload, keyed by id. */
    protected array $outletNames = [];

    /**
     * "for 20 Aug 18:30" when the customer picked a slot, "ASAP" otherwise (spec §8.2 —
     * the ticket detail B8 flagged as missing). The stored value is a checkout field, so a
     * hand-crafted request can put any string there; a snapshot must render years later, so
     * an unparseable value is shown truncated rather than thrown on.
     */
    protected function scheduleLabel(?string $scheduledAt): string
    {
        if (! $scheduledAt) {
            return 'ASAP';
        }

        try {
            return 'for ' . Carbon::parse($scheduledAt)->format('j M H:i');
        } catch (\Throwable $e) {
            return 'for ' . Str::limit($scheduledAt, 24);
        }
    }

    /**
     * The date an order is wanted on, or null when it is as soon as possible.
     *
     * The stored value is a checkout field — client-supplied, and a snapshot that must still
     * parse years later — so an unreadable one is treated as ASAP rather than thrown on. That
     * errs towards the live queue, which is the safe direction: an order shown to the kitchen
     * today is noticed, one filed under a date nobody reads is not.
     */
    protected function scheduledDate(Order $order): ?string
    {
        $raw = ($order->meta['checkout_fields']['scheduled_at'] ?? $order->meta['scheduled_at'] ?? null) ?: null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The forward book as text, one block per date: how many orders, and each one's time.
     *
     * Rendered through a `display` field like the rest of this screen, because the schema
     * engine has no list widget (register O15). Deliberately terse — this is a planning aid,
     * not a ticket; the tickets appear in the queue on the day, when the counter can act on
     * them.
     *
     * @param  array<string, array<int, Order>>  $upcoming
     */
    protected function upcomingSummary(array $upcoming): string
    {
        if ($upcoming === []) {
            return 'Nothing booked ahead.';
        }

        $blocks = [];

        foreach ($upcoming as $date => $orders) {
            $when = Carbon::parse($date);

            $lines = collect($orders)
                ->map(function (Order $order) {
                    $at = $this->scheduledTime($order);

                    return sprintf(
                        '   %s · %s%s',
                        $at ?: '—',
                        $order->order_number,
                        $order->customer?->name ? ' · ' . $order->customer->name : ''
                    );
                })
                ->sort()
                ->implode("\n");

            $blocks[] = sprintf(
                "%s — %d %s\n%s",
                $when->format('D j M'),
                count($orders),
                count($orders) === 1 ? 'order' : 'orders',
                $lines
            );
        }

        return implode("\n\n", $blocks);
    }

    /** The wall-clock time an order is wanted at, for the forward book's lines. */
    protected function scheduledTime(Order $order): ?string
    {
        $raw = ($order->meta['checkout_fields']['scheduled_at'] ?? $order->meta['scheduled_at'] ?? null) ?: null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * "26546 min" is a number nobody can read at a glance; "18 d 10 h" is. Minutes under an
     * hour stay minutes — that is the resolution a kitchen works in.
     */
    protected function waitLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        if ($minutes < 1440) {
            return intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
        }

        return intdiv($minutes, 1440) . ' d ' . intdiv($minutes % 1440, 60) . ' h';
    }

    /**
     * Derive the kitchen state from `meta.kitchen_state`, falling back to the status pair.
     *
     * The stored value used to win because it carried a distinction the status axes could not:
     * "ready at the counter" and "with the rider" were both `partial`. **Core gained
     * `ready` and `out_for_delivery` on 2026-08-20**, so the pair now says it on its own and
     * the fallback below is exact rather than a best guess. The stored key is still read
     * first, and still written, because an order placed before that change has one and its
     * fulfilment value is the older `partial`.
     *
     * Public because the order's own edit screen shows this too (`OrderKitchenState`), and
     * that screen sees every order, not only the queue's confirmed/processing ones. So the
     * fallback covers `completed` (Delivered) and answers `null` for an order the kitchen has
     * no state for — pending, on hold, cancelled — rather than calling an unpaid order
     * "Preparing". The queue never asks about those, so it reads exactly as before.
     */
    public function kitchenStateFor(Order $order): ?string
    {
        $stored = $order->meta['kitchen_state'] ?? null;

        if (is_string($stored) && isset(self::KITCHEN_STATES[$stored])) {
            return $stored;
        }

        return match ($order->status) {
            Order::STATUS_CONFIRMED  => 'new',
            Order::STATUS_PROCESSING => match ($order->fulfillment_status) {
                Order::FULFILLMENT_OUT     => 'out',
                Order::FULFILLMENT_READY   => 'ready',
                // An order written before core had the two states above: `partial` was what
                // Ready was stored as, and there is nothing finer to read.
                Order::FULFILLMENT_PARTIAL => 'ready',
                default                    => 'preparing',
            },
            Order::STATUS_COMPLETED  => 'delivered',
            default                  => null,
        };
    }

    /**
     * Move one order to a kitchen state — the one write both the queue's Advance card and the
     * order form's Kitchen tab perform, so the two screens cannot drift on what "Ready" means.
     *
     * Writes the status pair AND `meta.kitchen_state` on the given model. Note the guard core
     * enforces: `Order::contradictsCompletion()` rejects a `completed` order whose fulfillment
     * is still `unfulfilled` with a 422, so the queue must move fulfillment along rather than
     * jumping straight to completed — which is exactly what the state table above does.
     *
     * The caller owns the transaction: the queue opens its own with the row locked, and the
     * order form's handler already runs inside `OrderController`'s. A cancelled order refuses
     * with a `ValidationException`, which that controller answers as a 422.
     *
     * ## Through core's transition path, not straight onto the model
     *
     * This used to be a single `fill()->save()`, and it was a hole. `.agent/docs/orders.md`
     * states the invariant without qualification — *an order cannot be placed or transitioned
     * without emitting* — and a kitchen move was a third path that transitioned two axes and
     * emitted nothing. Measured on 2026-08-19 before the change: New → Preparing → Ready → Out
     * → Delivered dispatched **no** `OrderStatusChanged` at all, so no lifecycle email, no core
     * notification and no plugin listener saw a restaurant order move, ever. A loyalty plugin
     * awarding points on `status → completed` would never have fired for a food shop.
     *
     * So each axis that moves now goes through `OrderRepository::transitionStatus()`, which is
     * the same call `POST /admin/orders/{id}/transition` makes. Two consequences worth knowing:
     *
     * - **The buyer is told by this theme, not by core.** Every transition is made with
     *   `notifyCustomer: false` and {@see notifyKitchenState()} sends the food wording instead.
     *   Without that a diner is emailed "Partially fulfilled" when their meal is ready and
     *   "Shipped" when they collect it — core's vocabulary is orthogonal status axes, and it
     *   has no way to know one of them means a curry is on the pass. Everything that is not a
     *   message to the buyer still runs: the activity log, the stock settlement, plugins.
     * - **The order the axes move in is load-bearing.** `contradictsCompletion()` rejects
     *   `completed` + `unfulfilled`, so moving *to* Delivered sets fulfillment first, and moving
     *   *away* from it sets status first. Either sequence passes through a legal pair; the two
     *   opposite ones both 422.
     *
     * `meta.kitchen_state` is filled **before** the transitions rather than saved after, so it
     * rides along on the first axis's own write. `save()` persists everything dirty, and this is
     * the busiest table in the application — an extra row per kitchen move, per order, per
     * service is a cost with nothing to show for it. The trailing `save()` catches Ready → Out,
     * which is one kitchen state to another and no status change at all.
     */
    public function moveKitchenOrder(Order $order, string $target): void
    {
        if (!isset(self::KITCHEN_STATES[$target])) {
            throw ValidationException::withMessages(['kitchen_state' => "Unknown kitchen state: {$target}"]);
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['kitchen_state' => 'That order was cancelled.']);
        }

        $state = self::KITCHEN_STATES[$target];

        $order->fill(['meta' => array_merge($order->meta ?? [], ['kitchen_state' => $target])]);

        $orders = app(OrderInterface::class);
        $note   = "Kitchen: {$state['label']}";

        $axes = [
            ['status', $state['status']],
            ['fulfillment_status', $state['fulfillment_status']],
        ];

        // Fulfillment first only when the destination is a completed order; see the docblock.
        if ($state['status'] === Order::STATUS_COMPLETED) {
            $axes = array_reverse($axes);
        }

        foreach ($axes as [$field, $to]) {
            // Only an axis that genuinely moves. `transitionStatus()` declines to dispatch on a
            // no-op but still writes its activity row, and Ready → Out for delivery moves
            // neither axis — two rows saying nothing happened, on every ticket that leaves.
            if ($order->{$field} !== $to) {
                $orders->transitionStatus($order, $field, $to, $note, notifyCustomer: false);
            }
        }

        // A safety net rather than a live branch. Every pair in the table above is distinct
        // since core gained `ready` and `out_for_delivery` — before that, Ready → Out moved
        // neither axis and nothing else would have written the meta key. Kept because the
        // table is data: give two states the same pair again and this is what stops the move
        // from being silently lost.
        if ($order->isDirty()) {
            $order->save();
        }

        $this->notifyKitchenState($order, $target, $state['label']);
    }

    /**
     * Which type each kitchen state tells the customer through, and `null` for the states
     * that tell them nothing.
     *
     * Spec §11's table, mapped onto the states this theme actually has:
     *
     * | §11 trigger                | State       | Who sends it                        |
     * |----------------------------|-------------|-------------------------------------|
     * | Order placed               | —           | core, `order_placed` (email)         |
     * | New order → the shop       | —           | core, `core.order_placed` (staff)    |
     * | Order accepted, prep begun | `preparing` | `order_preparing`                    |
     * | Ready / out for delivery   | `ready`,`out`| `order_ready`                       |
     * | Delivered                  | `delivered` | `order_delivered`                    |
     * | Rejected                   | —           | core, on the cancellation            |
     *
     * **`new` is deliberately silent.** It is the state an order is already in when it
     * reaches the queue, so telling the customer would mean a bell entry saying what the
     * confirmation page in front of them says — core declines a customer "we received your
     * order" notification for exactly that reason, and this is the same moment.
     *
     * **`ready` and `out` share one type**, as §11 shares one row for them: the difference
     * between "come and collect it" and "the rider has it" is a phrase, not a different
     * message, and one type means one email template for an operator to word rather than two
     * that must be kept saying the same thing. `state_label` carries the phrase.
     *
     * @return array{0: string, 1: string}|null [short type key, the phrase for `state_label`]
     */
    protected function customerNotificationFor(string $target, string $label): ?array
    {
        return match ($target) {
            'preparing' => ['order_preparing', strtolower($label)],
            'ready'     => ['order_ready', strtolower($label)],
            'out'       => ['order_ready', 'on its way'],
            'delivered' => ['order_delivered', strtolower($label)],
            default     => null,
        };
    }

    /**
     * Tell the customer what the kitchen just did.
     *
     * **Called from here rather than from an event listener**, and that is the theme seam
     * working as designed: a theme has no service provider, so it cannot subscribe to
     * `App\Events\*` the way a plugin can. It does not need to — this is the single write
     * both the Kitchen Queue's Advance card and the order form's Kitchen tab go through, so
     * calling `Notifier` here catches every kitchen move there is.
     *
     * **This is the whole of what the buyer hears about a kitchen move**, because
     * {@see moveKitchenOrder()} transitions with `notifyCustomer: false`. It used to be a
     * supplement to core's own status messages; it is now the replacement for them, which is
     * why Delivered is here. The previous note said Delivered *"reaches the customer through
     * core's own fulfilment notification"* — that was measured on 2026-08-19 and found false
     * even then: no kitchen move emitted an event, so nothing of core's had ever fired for one.
     *
     * Each type declares `channels: ["inapp", "mail"]`, so one call produces the bell entry
     * and the email Q4 asked for. Core owns both — `MailChannel` hands off to `TemplatedMailer`
     * and `NotificationMailTemplates` seeds an editable `EmailTemplate` on the send path — so
     * this theme ships wording, not a mail system.
     *
     * A guest order has no account to address, so there is nobody to notify: `toUser()` needs a
     * `User`, a preference switch needs somebody to own it, and an in-app feed needs an account
     * to be a feed of. The order confirmation email still reached them at checkout, and the
     * kitchen's own moves reach them not at all — stated as a limitation rather than papered
     * over, because fixing it means addressing mail to an order's billing address, which is a
     * different mechanism from a notification.
     *
     * `Notifier` never throws and answers `0` on anything it cannot do — which matters here,
     * because this runs inside the caller's transaction and a failed notification must not
     * cost the kitchen its state change.
     */
    protected function notifyKitchenState(Order $order, string $target, string $label): void
    {
        $wording = $this->customerNotificationFor($target, $label);

        if ($wording === null || ! $order->user_id) {
            return;
        }

        [$key, $stateLabel] = $wording;

        // `themeKey()`, not a hardcoded `theme:saffron.…`. The deployed slug comes from the
        // manifest *title*, so this theme installs as `alvyth-saffron-theme` while its manifest
        // says `saffron` — a written-out prefix is a key the registry never issued, and the
        // notification silently goes nowhere.
        app(Notifier::class)->toUser(
            $order->user_id,
            app(NotificationTypeRegistry::class)->themeKey($key),
            [
                'order_number' => $order->order_number,
                'state_label'  => $stateLabel,
            ],
            $order,
        );
    }

    /**
     * Advance one order to the next kitchen state, from the queue's Advance An Order card.
     *
     * One transaction with the order row locked; the write itself is {@see moveKitchenOrder()}.
     */
    protected function advanceKitchenOrder(array $data): array
    {
        $orderId = (int) ($data['order_id'] ?? 0);
        $target  = (string) ($data['to_state'] ?? '');

        if (!$orderId) {
            return ['message' => 'No order was named.', 'ok' => false];
        }

        if (!isset(self::KITCHEN_STATES[$target])) {
            return ['message' => "Unknown kitchen state: {$target}", 'ok' => false];
        }

        $state = self::KITCHEN_STATES[$target];

        return DB::transaction(function () use ($orderId, $target, $state) {
            $order = Order::lockForUpdate()->find($orderId);

            if (!$order) {
                return ['message' => 'That order no longer exists.', 'ok' => false];
            }

            try {
                $this->moveKitchenOrder($order, $target);
            } catch (ValidationException $e) {
                return ['message' => $e->getMessage(), 'ok' => false];
            }

            return [
                'message' => "Order {$order->order_number} moved to {$state['label']}.",
                'ok'      => true,
                'state'   => $target,
            ];
        });
    }

    // ── Storefront helpers ──────────────────────────────────────────────────────

    /**
     * The shop's own timezone — the wall clock every window in this table is authored in.
     *
     * One accessor rather than `config('app.timezone')` repeated in each reader, so the day a
     * shop timezone becomes its own setting there is a single place to change. A 09:00 window
     * means 09:00 *there*, DST or not: comparisons happen on local wall-clock and only the
     * display is converted.
     */
    public function timezone(?int $outletId = null): string
    {
        // A branch judging its own hours judges them on its own wall clock. The outlets form
        // stored this column from the start with a hint promising exactly this the day
        // per-branch hours shipped — this is that day. Free text, so it is trusted only when
        // it names a real zone: "GMT+8" typed in good faith must degrade to the shop's clock,
        // not take checkout down with an InvalidTimeZoneException.
        if ($outletId) {
            $tz = trim((string) (Outlet::query()->whereKey($outletId)->value('timezone') ?? ''));

            if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
                return $tz;
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    /**
     * How many days ahead a customer may schedule — today counts as the first.
     *
     * **One clamp, read by both halves of the promise.** The cart's picker offers this many
     * days and the checkout guard refuses anything past it, and they were computing it
     * separately: change the default in one and the shop offers a slot the server then turns
     * away, which is the single worst way this feature can fail.
     *
     * The ceiling and the fallback mirror `admin/settings/restaurant.json`'s own `max:365` rule
     * and its default — they have to, because a clamp tighter than the field silently ignores
     * what the operator typed: set 30 days, get 14, with nothing on screen to say why.
     */
    public function schedulingHorizon(): int
    {
        return min(365, max(1, (int) ThemeSettings::get('scheduling_days_ahead', 30)));
    }

    /** Weekly windows belonging to the shop rather than to one branch or menu section. */
    protected function shopWindows()
    {
        return ServiceWindow::recurring()->where('status', 'active')->forShop();
    }

    /** Weekly windows one branch authored for itself. */
    protected function branchWindows(int $outletId)
    {
        return ServiceWindow::recurring()->where('status', 'active')->forOutlet($outletId);
    }

    /**
     * Whether this branch keeps its own week — memoised per instance, because `openState()`
     * walks up to seven days and the picker loops the horizon, and the answer cannot change
     * mid-request. Tests that write windows between assertions should resolve a fresh
     * repository rather than reuse one across the write.
     */
    private array $branchHasWeek = [];

    protected function branchKeepsOwnWeek(int $outletId): bool
    {
        return $this->branchHasWeek[$outletId] ??= $this->branchWindows($outletId)->exists();
    }

    /**
     * A branch context that the readers may honour — or null.
     *
     * The same principle as the branch-menu readers (and proved there the hard way): scope may
     * only be granted by a branch a customer could actually choose. A stale cookie or a
     * hand-crafted field can name a branch that has since been deactivated or deleted, and
     * honouring its private hours would judge a live order by a dead branch's clock. Such a
     * context falls back to the shop's own hours instead.
     */
    private array $usableOutlet = [];

    protected function resolvedOutletId(?int $outletId): ?int
    {
        if (! $outletId) {
            return null;
        }

        $usable = $this->usableOutlet[$outletId]
            ??= Outlet::query()->active()->whereKey($outletId)->exists();

        return $usable ? $outletId : null;
    }

    /** Has the operator authored any hours this context would read at all? */
    public function hasHours(?int $outletId = null): bool
    {
        $outletId = $this->resolvedOutletId($outletId);

        if ($outletId && $this->branchKeepsOwnWeek($outletId)) {
            return true;
        }

        return $this->shopWindows()->exists();
    }

    /**
     * What the shop's hours say about one date: the orderable spans, and why there are none.
     *
     * **The single answer three readers share** — the Store Status banner, the cart's time
     * picker, and the checkout guard that now refuses an out-of-hours order. The first two had
     * separate implementations and disagreed: the banner counted a *section*-scoped window
     * ("Breakfast") as the shop being open, while the picker never did, so a shop could
     * advertise Open and offer no slot. A third copy inside the guard would have been worse
     * than a duplicate — a refusal that disagrees with what the page displayed tells the
     * customer they may order and then refuses them.
     *
     * Precedence is what the operator's own screen presents: a dated entry wins its date
     * outright, and the weekly windows are consulted only when there is none. Section-scoped
     * rows are not the shop's hours and never appear here.
     *
     * With a branch in play the same shape repeats one level down. The branch's own dated
     * entry wins first — then the shop's, because a shop-wide holiday closes every branch
     * unless that branch says otherwise for that date. For the week itself, **a branch with
     * any weekly hours of its own keeps its whole week**: a day it does not author is a day
     * it is closed there, never a day it inherits from the shop. Per-day fallback reads
     * friendlier right up until a kiosk closed at weekends authors Monday–Friday and silently
     * inherits the shop's Saturday — the same trap, in miniature, as the outlet_tables
     * fallback this rule is modelled on avoiding.
     *
     * `source: unconfigured` means no hours this context would read are authored at all,
     * which is deliberately **not** the same as closed. A shop that never filled the screen
     * in must not have every order refused, so every caller reads it as "no opinion".
     *
     * @return array{spans: array<int,array{opens:string,closes:string,mode:string}>, source: string, reason: ?string}
     */
    public function hoursForDate(Carbon $date, ?int $outletId = null): array
    {
        $outletId = $this->resolvedOutletId($outletId);

        // Exceptions and windows share a table, so these are queries narrowed by `kind`
        // rather than lookups in a second model.
        $exception = null;

        if ($outletId) {
            $exception = ServiceWindow::exceptions()
                ->where('status', 'active')
                ->forOutlet($outletId)
                ->whereDate('date', $date->toDateString())
                ->first();
        }

        $exception ??= ServiceWindow::exceptions()
            ->where('status', 'active')
            ->forShop()
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($exception) {
            $reason = $exception->getTranslation('reason', app()->getLocale(), false)
                ?: $exception->getTranslation('reason', 'en', false);

            return [
                // `closesTheDay()` also covers an override missing either time, so half a
                // window never becomes an opening.
                'spans'  => $exception->closesTheDay() ? [] : [[
                    'opens'  => substr((string) $exception->opens_at, 0, 5),
                    'closes' => substr((string) $exception->closes_at, 0, 5),
                    'mode'   => (string) ($exception->mode ?: 'both'),
                ]],
                'source' => 'exception',
                'reason' => $reason ?: null,
            ];
        }

        $weekly = $outletId && $this->branchKeepsOwnWeek($outletId)
            ? $this->branchWindows($outletId)
            : $this->shopWindows();

        $spans = $weekly
            ->where('day_of_week', (int) $date->dayOfWeek)
            ->orderBy('opens_at')
            ->get()
            ->map(fn (ServiceWindow $window) => [
                'opens'  => substr((string) $window->opens_at, 0, 5),
                'closes' => substr((string) $window->closes_at, 0, 5),
                'mode'   => (string) ($window->mode ?: 'both'),
            ])
            ->all();

        if ($spans === [] && ! $this->hasHours($outletId)) {
            return ['spans' => [], 'source' => 'unconfigured', 'reason' => null];
        }

        return ['spans' => $spans, 'source' => 'window', 'reason' => null];
    }

    /**
     * The weekly hours as seven lists of spans, keyed 0 = Sunday.
     *
     * For the cart's date picker, which lets a customer name any date inside the horizon —
     * a party in September, a catering order in November. Asking {@see hoursForDate()} per
     * date would be two queries a day and ninety of them on one page load, and a theme cannot
     * register an endpoint to ask later, so the pattern is handed to the browser once and the
     * chosen date is resolved there.
     *
     * **The browser's answer is a courtesy; the guard's is the one that counts.** The same
     * precedence is applied on both sides — a dated entry wins its date, weekly hours fill the
     * rest — and `ServiceWindowGuard` re-derives it through `hoursForDate()` at checkout. A
     * drift shows up as a refusal, never as an order the kitchen cannot cook.
     *
     * @return array<int, array<int, array{opens:string, closes:string}>>
     */
    public function weeklyPattern(?int $outletId = null): array
    {
        $outletId = $this->resolvedOutletId($outletId);

        $windows = $outletId && $this->branchKeepsOwnWeek($outletId)
            ? $this->branchWindows($outletId)
            : $this->shopWindows();

        $pattern = array_fill(0, 7, []);

        foreach ($windows->orderBy('opens_at')->get() as $window) {
            $pattern[(int) $window->day_of_week][] = [
                'opens'  => substr((string) $window->opens_at, 0, 5),
                'closes' => substr((string) $window->closes_at, 0, 5),
            ];
        }

        return $pattern;
    }

    /**
     * Dated overrides falling inside a range, keyed by date, in the shape the pattern uses.
     *
     * A closed day carries no spans, which is exactly how the browser should read it — the
     * same collapse `closesTheDay()` performs, so a holiday missing one of its times cannot
     * become half an opening on the client either.
     *
     * @return array<string, array{spans: array<int, array{opens:string, closes:string}>, reason: ?string}>
     */
    public function exceptionsBetween(Carbon $from, Carbon $to, ?int $outletId = null): array
    {
        $outletId = $this->resolvedOutletId($outletId);

        $out = [];

        // Shop-wide overrides first, then the branch's own on top — the same per-date
        // precedence `hoursForDate()` applies, so the browser's calendar and the server's
        // refusal cannot disagree about whose holiday a date is.
        $sets = [ServiceWindow::exceptions()->where('status', 'active')->forShop()];

        if ($outletId) {
            $sets[] = ServiceWindow::exceptions()->where('status', 'active')->forOutlet($outletId);
        }

        foreach ($sets as $query) {
            $rows = $query
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->get();

            foreach ($rows as $row) {
                $reason = $row->getTranslation('reason', app()->getLocale(), false)
                    ?: $row->getTranslation('reason', 'en', false);

                $out[Carbon::parse($row->date)->toDateString()] = [
                    'spans'  => $row->closesTheDay() ? [] : [[
                        'opens'  => substr((string) $row->opens_at, 0, 5),
                        'closes' => substr((string) $row->closes_at, 0, 5),
                    ]],
                    'reason' => $reason ?: null,
                ];
            }
        }

        return $out;
    }

    /**
     * Whether the shop is open at a given moment, and when it next opens.
     *
     * Read by the StoreStatus banner and by the checkout guard's "is the shop open right now"
     * refusal, both through {@see hoursForDate()} so the banner and the refusal cannot say
     * different things.
     */
    public function openState(?string $timezone = null, ?Carbon $at = null, ?int $outletId = null): array
    {
        $outletId = $this->resolvedOutletId($outletId);

        $tz  = $timezone ?: $this->timezone($outletId);
        $now = ($at ? $at->copy() : Carbon::now())->setTimezone($tz);

        $today = $this->hoursForDate($now, $outletId);

        // The shop has not configured hours. Report open — refusing every order because a
        // screen was never filled in would be worse than the alternative.
        if ($today['source'] === 'unconfigured') {
            return ['open' => true, 'reason' => null, 'next_open' => null, 'source' => 'unconfigured'];
        }

        $localTime = $now->format('H:i');
        $reason    = $today['reason'];

        foreach ($today['spans'] as $span) {
            if ($localTime >= $span['opens'] && $localTime < $span['closes']) {
                return [
                    'open'      => true,
                    'reason'    => null,
                    'closes_at' => $span['closes'],
                    'mode'      => $span['mode'],
                    'source'    => $today['source'],
                ];
            }
        }

        // Still to come today. Ordered by `opens_at`, so the first one past now is the next.
        foreach ($today['spans'] as $span) {
            if ($span['opens'] > $localTime) {
                return [
                    'open'      => false,
                    'reason'    => $reason,
                    'next_open' => $span['opens'],
                    'next_day'  => null,
                    'source'    => $today['source'],
                ];
            }
        }

        // Walk forward at most seven days to find the next opening. A dated closure is
        // included in the walk, so a holiday reports the day after it rather than the hours it
        // cancelled — the banner used to answer "closed" with no reopening time at all.
        for ($i = 1; $i <= 7; $i++) {
            $day   = $now->copy()->addDays($i);
            $hours = $this->hoursForDate($day, $outletId);

            if ($hours['spans'] !== []) {
                return [
                    'open'      => false,
                    'reason'    => $reason,
                    'next_open' => $hours['spans'][0]['opens'],
                    'next_day'  => $day->format('l'),
                    'source'    => $today['source'],
                ];
            }
        }

        return ['open' => false, 'reason' => $reason, 'next_open' => null, 'source' => $today['source']];
    }

    /**
     * Resolve an order line's snapshotted title, which is stored as a locale map.
     */
    protected function lineTitle(mixed $title): string
    {
        if (is_array($title)) {
            $locale = app()->getLocale();

            return (string) ($title[$locale] ?? $title['en'] ?? (count($title) ? reset($title) : '—'));
        }

        return (string) ($title ?: '—');
    }

    /**
     * Turn a stored options bag into "Size: Large · Extras: Cheese, Bacon" for the queue card.
     *
     * The bag is flat and string-valued by construction, but an order line is a snapshot that
     * must still render years later, so nested or non-scalar values are tolerated rather than
     * assumed away.
     */
    protected function flattenOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $out = [];

        foreach ($options as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $out[] = ['label' => is_string($label) ? $label : '', 'value' => $value];
        }

        return $out;
    }
}
