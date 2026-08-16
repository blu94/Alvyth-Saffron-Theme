<?php

namespace Theme\Backend\Repositories;

use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Theme\Backend\Models\ServiceWindow;

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
     * The queue, as five columns of cards.
     *
     * Refreshed by polling, not websockets: there is no Node in production, no
     * `config/broadcasting.php` at all, and `.env.example` ships `BROADCAST_CONNECTION=log`.
     * A ten-second poll is the honest mechanism here.
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

        foreach ($orders as $order) {
            $key = $this->kitchenStateFor($order);

            if (!isset($columns[$key])) {
                continue;
            }

            $columns[$key]['orders'][] = [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'placed_at'    => optional($order->created_at)->toDateTimeString(),
                // Carbon 3 returns a float here; the label and the chart both want whole minutes.
                'waiting_mins' => (int) (optional($order->created_at)->diffInMinutes(now()) ?? 0),
                'customer'     => $order->customer?->name ?? 'Guest',
                'mode'         => $order->meta['ordering_mode'] ?? ($order->shipping_address_id ? 'delivery' : 'pickup'),
                'scheduled_at' => $order->meta['scheduled_at'] ?? null,
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
        $flat = [
            'columns'      => array_values($columns),
            'total_open'   => (string) $orders->count(),
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

        return $flat;
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

        return implode(' · ', $header) . "\n" . $lines . ($o['note'] ? "\n   Note: " . $o['note'] : '');
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
     */
    protected function kitchenStateFor(Order $order): string
    {
        $stored = $order->meta['kitchen_state'] ?? null;

        if (is_string($stored) && isset(self::KITCHEN_STATES[$stored])) {
            return $stored;
        }

        if ($order->status === Order::STATUS_CONFIRMED) {
            return 'new';
        }

        return $order->fulfillment_status === Order::FULFILLMENT_PARTIAL ? 'ready' : 'preparing';
    }

    /**
     * Advance one order to the next kitchen state.
     *
     * Writes the status pair AND `meta.kitchen_state` in one transaction. Note the guard core
     * enforces: `Order::contradictsCompletion()` rejects a `completed` order whose fulfillment
     * is still `unfulfilled` with a 422, so the queue must move fulfillment along rather than
     * jumping straight to completed — which is exactly what the state table above does.
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

            if ($order->status === Order::STATUS_CANCELLED) {
                return ['message' => 'That order was cancelled.', 'ok' => false];
            }

            $order->fill([
                'status'             => $state['status'],
                'fulfillment_status' => $state['fulfillment_status'],
                'meta'               => array_merge($order->meta ?? [], ['kitchen_state' => $target]),
            ])->save();

            return [
                'message' => "Order {$order->order_number} moved to {$state['label']}.",
                'ok'      => true,
                'state'   => $target,
            ];
        });
    }

    // ── Storefront helpers ──────────────────────────────────────────────────────

    /**
     * Whether the shop is open at a given moment, and when it next opens.
     *
     * Read by the StoreStatus section. Everything is computed in the SHOP's timezone: a 09:00
     * window means 09:00 there, DST or not, so the comparison is done on local wall-clock and
     * only the display is converted. Presentation only — see §14 item 6.
     */
    public function openState(?string $timezone = null, ?Carbon $at = null): array
    {
        $tz  = $timezone ?: config('app.timezone', 'UTC');
        $now = ($at ? $at->copy() : Carbon::now())->setTimezone($tz);

        // Exceptions and windows now share a table, so this is one query narrowed by `kind`
        // rather than a lookup in a second model. The precedence is unchanged: a dated
        // override wins for its date, and the weekly windows are only consulted when there
        // is none.
        $exception = ServiceWindow::exceptions()
            ->where('status', 'active')
            ->whereDate('date', $now->toDateString())
            ->first();

        if ($exception) {
            $reason = $exception->getTranslation('reason', app()->getLocale(), false)
                ?: $exception->getTranslation('reason', 'en', false);

            if ($exception->closesTheDay()) {
                return ['open' => false, 'reason' => $reason ?: null, 'next_open' => null, 'source' => 'exception'];
            }

            return [
                'open'      => $exception->covers($now->format('H:i')),
                'reason'    => $reason ?: null,
                'next_open' => $exception->covers($now->format('H:i')) ? null : substr((string) $exception->opens_at, 0, 5),
                'source'    => 'exception',
            ];
        }

        $today = ServiceWindow::recurring()->where('status', 'active')
            ->where('day_of_week', (int) $now->dayOfWeek)
            ->orderBy('opens_at')
            ->get();

        // No windows authored at all means the shop has not configured hours. Report open —
        // refusing every order because a table is empty would be worse than the alternative,
        // and nothing here is an enforcement point anyway.
        if ($today->isEmpty() && ServiceWindow::recurring()->where('status', 'active')->doesntExist()) {
            return ['open' => true, 'reason' => null, 'next_open' => null, 'source' => 'unconfigured'];
        }

        $localTime = $now->format('H:i');

        foreach ($today as $window) {
            if ($window->covers($localTime)) {
                return [
                    'open'       => true,
                    'reason'     => null,
                    'closes_at'  => substr((string) $window->closes_at, 0, 5),
                    'mode'       => $window->mode,
                    'source'     => 'window',
                ];
            }
        }

        $laterToday = $today->first(fn ($w) => substr((string) $w->opens_at, 0, 5) > $localTime);

        if ($laterToday) {
            return [
                'open'      => false,
                'reason'    => null,
                'next_open' => substr((string) $laterToday->opens_at, 0, 5),
                'next_day'  => null,
                'source'    => 'window',
            ];
        }

        // Walk forward at most seven days to find the next opening.
        for ($i = 1; $i <= 7; $i++) {
            $day  = $now->copy()->addDays($i);
            $next = ServiceWindow::recurring()->where('status', 'active')
                ->where('day_of_week', (int) $day->dayOfWeek)
                ->orderBy('opens_at')
                ->first();

            if ($next) {
                return [
                    'open'      => false,
                    'reason'    => null,
                    'next_open' => substr((string) $next->opens_at, 0, 5),
                    'next_day'  => $day->format('l'),
                    'source'    => 'window',
                ];
            }
        }

        return ['open' => false, 'reason' => null, 'next_open' => null, 'source' => 'window'];
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
