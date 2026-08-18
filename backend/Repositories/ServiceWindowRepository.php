<?php

namespace Theme\Backend\Repositories;

use App\Contracts\Notification\Notifier;
use App\Models\Order;
use App\Models\Product;
use App\Services\Notification\NotificationTypeRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
     * Kitchen states, and how each maps onto Ovynt's three orthogonal status axes.
     *
     * A kitchen thinks in states the generic vocabulary does not name. Resisting a fourth
     * status column is deliberate: `ReportRepository` counts revenue by `payment_status` and
     * excludes cancelled orders, so adding food states to `Order::STATUSES` would change what
     * every existing report means. Where the mapping is genuinely lossy — "ready for
     * collection" versus "with the rider" — the finer state goes in `meta.kitchen_state`,
     * which is what `column` below reads.
     */
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
            'fulfillment_status' => Order::FULFILLMENT_PARTIAL,
            'next'               => 'out',
        ],
        'out' => [
            'label'              => 'Out for delivery',
            'status'             => Order::STATUS_PROCESSING,
            'fulfillment_status' => Order::FULFILLMENT_PARTIAL,
            'next'               => 'delivered',
        ],
        'delivered' => [
            'label'              => 'Delivered',
            'status'             => Order::STATUS_COMPLETED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'next'               => null,
        ],
    ];

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

        // Weekly rows first and in week order, then the dated overrides in date order. Both
        // sort keys are null for the other kind, so a single `orderBy` pair would interleave
        // holidays through the week at whatever position null happens to sort.
        return $query
            ->orderBy('kind')
            ->orderByRaw('day_of_week IS NULL, day_of_week')
            ->orderByRaw('date IS NULL, date')
            ->orderBy('opens_at');
    }

    public function find($id)
    {
        return ServiceWindow::find($id);
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
    public function getOptions(array $columns = [])
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

                'kitchen_state' => collect(self::KITCHEN_STATES)
                    ->map(fn ($state, $key) => ['label' => $state['label'], 'value' => $key])
                    ->values()
                    ->all(),

                'open_order' => Order::query()
                    ->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING])
                    ->orderBy('created_at')
                    ->limit(200)
                    ->get(['id', 'order_number', 'grand_total', 'created_at'])
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
                'kind', 'scope_type', 'scope_id', 'day_of_week', 'date',
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

        // An empty scope means the whole shop. Store both halves as null rather than a
        // dangling type with no id, which morphTo would try to resolve.
        if (empty($out['scope_id'])) {
            $out['scope_id']   = null;
            $out['scope_type'] = null;
        }

        return $out;
    }

    // ── Custom admin pages ──────────────────────────────────────────────────────

    public function pageData(string $slug)
    {
        return match ($slug) {
            'kitchen' => $this->kitchenData(),
            default   => [],
        };
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
     */
    protected function kitchenData(): array
    {
        $orders = Order::query()
            ->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING])
            // The relation is `customer`, not `user` — Order has a `user_id` column but names
            // the belongsTo after the role, and a guest order has none.
            ->with(['items', 'customer'])
            ->orderBy('created_at')
            ->limit(120)
            ->get();

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

        $header = [$o['order_number'], strtoupper((string) $o['mode'])];

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
        $id = $order->meta['checkout_fields']['outlet_id'] ?? $order->meta['outlet_id'] ?? null;

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
     * The stored value wins because it carries the distinction the status axes cannot —
     * "ready at the counter" and "with the rider" are the same pair.
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
            Order::STATUS_PROCESSING => $order->fulfillment_status === Order::FULFILLMENT_PARTIAL ? 'ready' : 'preparing',
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

        $order->fill([
            'status'             => $state['status'],
            'fulfillment_status' => $state['fulfillment_status'],
            'meta'               => array_merge($order->meta ?? [], ['kitchen_state' => $target]),
        ])->save();

        $this->notifyKitchenState($order, $target, $state['label']);
    }

    /**
     * Tell the customer their food is ready.
     *
     * **Called from here rather than from an event listener**, and that is the theme seam
     * working as designed: a theme has no service provider, so it cannot subscribe to
     * `App\Events\*` the way a plugin can. It does not need to — this is the single write
     * both the Kitchen Queue's Advance card and the order form's Kitchen tab go through, so
     * calling `Notifier` here catches every kitchen move there is.
     *
     * Only `ready` and `out`. `new` and `preparing` are the kitchen talking to itself, and
     * `delivered` reaches the customer through core's own fulfilment notification — sending a
     * second one would be this theme saying the same thing twice.
     *
     * A guest order has no account to address, so there is nobody to notify. The core
     * lifecycle email still reaches them, and an in-app feed needs an account to be a feed of.
     *
     * `Notifier` never throws and answers `0` on anything it cannot do — which matters here,
     * because this runs inside the caller's transaction and a failed notification must not
     * cost the kitchen its state change.
     */
    protected function notifyKitchenState(Order $order, string $target, string $label): void
    {
        if (! in_array($target, ['ready', 'out'], true) || ! $order->user_id) {
            return;
        }

        // `themeKey()`, not a hardcoded `theme:saffron.…`. The deployed slug comes from the
        // manifest *title*, so this theme installs as `ovynt-saffron-theme` while its manifest
        // says `saffron` — a written-out prefix is a key the registry never issued, and the
        // notification silently goes nowhere.
        app(Notifier::class)->toUser(
            $order->user_id,
            app(NotificationTypeRegistry::class)->themeKey('order_ready'),
            [
                'order_number' => $order->order_number,
                'state_label'  => $target === 'out' ? 'on its way' : strtolower($label),
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
    public function timezone(): string
    {
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

    /** Weekly windows belonging to the shop rather than to one menu section. */
    protected function shopWindows()
    {
        return ServiceWindow::recurring()->where('status', 'active')->whereNull('scope_id');
    }

    /** Has the operator authored any whole-shop hours at all? */
    public function hasHours(): bool
    {
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
     * `source: unconfigured` means no whole-shop hours are authored at all, which is
     * deliberately **not** the same as closed. A shop that never filled the screen in must not
     * have every order refused, so every caller reads it as "no opinion".
     *
     * @return array{spans: array<int,array{opens:string,closes:string,mode:string}>, source: string, reason: ?string}
     */
    public function hoursForDate(Carbon $date): array
    {
        // Exceptions and windows share a table, so this is one query narrowed by `kind`
        // rather than a lookup in a second model.
        $exception = ServiceWindow::exceptions()
            ->where('status', 'active')
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

        $spans = $this->shopWindows()
            ->where('day_of_week', (int) $date->dayOfWeek)
            ->orderBy('opens_at')
            ->get()
            ->map(fn (ServiceWindow $window) => [
                'opens'  => substr((string) $window->opens_at, 0, 5),
                'closes' => substr((string) $window->closes_at, 0, 5),
                'mode'   => (string) ($window->mode ?: 'both'),
            ])
            ->all();

        if ($spans === [] && ! $this->hasHours()) {
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
    public function weeklyPattern(): array
    {
        $pattern = array_fill(0, 7, []);

        foreach ($this->shopWindows()->orderBy('opens_at')->get() as $window) {
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
    public function exceptionsBetween(Carbon $from, Carbon $to): array
    {
        $rows = ServiceWindow::exceptions()
            ->where('status', 'active')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $out = [];

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

        return $out;
    }

    /**
     * Whether the shop is open at a given moment, and when it next opens.
     *
     * Read by the StoreStatus banner and by the checkout guard's "is the shop open right now"
     * refusal, both through {@see hoursForDate()} so the banner and the refusal cannot say
     * different things.
     */
    public function openState(?string $timezone = null, ?Carbon $at = null): array
    {
        $tz  = $timezone ?: $this->timezone();
        $now = ($at ? $at->copy() : Carbon::now())->setTimezone($tz);

        $today = $this->hoursForDate($now);

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
            $hours = $this->hoursForDate($day);

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
