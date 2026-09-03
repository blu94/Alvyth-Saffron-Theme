<?php

namespace Theme\Backend\Repositories;

use App\Models\Order;
use Carbon\Carbon;
use Theme\Backend\Models\TableBooking;
use Theme\Backend\Repositories\ServiceWindowRepository;

/**
 * The Table Bookings screen's server side (register D-21, second half).
 *
 * The release RULE shipped first: a booking holds its table only while its order still stands
 * ({@see TableBooking::scopeHolding()}), so no table is ever stuck. What an operator lacked was
 * a single view of the evening — which tables are spoken for, from when to when, for how many —
 * and a way to give one up by hand: a diner who phones to cancel has an order nobody wants to
 * cancel (it may be paid; the food may be cooked) but a table everybody wants back.
 *
 * List and one action only, on purpose. Bookings are WRITTEN by the storefront checkout
 * (`TableReservation`, behind its lock); an admin form that could edit spans or move tables
 * would be a second writer to a row whose whole design is that one writer holds the lock. So
 * there is no create, no update, no delete — a booking leaves the evening by `release`, never
 * by disappearing, which is also why the model has no SoftDeletes.
 */
class TableBookingRepository
{
    public function baseIndexQuery(array $filters = [])
    {
        // Joins rather than relations, because the list filters and displays on the joined
        // columns. `orders` is joined raw, so soft-deleted orders still appear — a booking
        // whose order was deleted is history worth listing, shown as `freed`. Tables and
        // branches are LEFT joins for the same reason: a table deleted at head office does
        // not take tonight's booking row off the screen.
        $locale = preg_replace('/[^A-Za-z_-]/', '', app()->getLocale()) ?: 'en';

        $query = TableBooking::query()
            ->join('orders', 'orders.id', '=', 'table_bookings.order_id')
            ->leftJoin('outlet_tables', 'outlet_tables.id', '=', 'table_bookings.outlet_table_id')
            ->leftJoin('outlets', 'outlets.id', '=', 'outlet_tables.outlet_id')
            ->select([
                'table_bookings.*',
                'outlet_tables.label as table_label',
                'outlet_tables.outlet_id as outlet_id',
                'orders.order_number as order_number',
            ])
            // The branch by name, in the operator's locale, falling back the way the pickers
            // do: the locale's title, then English, then the slug.
            ->selectRaw(
                "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(outlets.title, ?)), 'null'),"
                . " NULLIF(JSON_UNQUOTE(JSON_EXTRACT(outlets.title, '$.\"en\"')), 'null'),"
                . ' outlets.slug) as branch',
                ['$."' . $locale . '"']
            )
            // Formatted HERE, as text, because these columns hold the shop's wall clock with
            // no timezone — `TableReservation` writes the digits straight in. The client's
            // `datetime` column type assumes UTC and shifts by the browser's offset, which
            // turned tonight's 18:30 sitting into tomorrow's 02:30 on the first render of
            // this screen. Text cannot be shifted.
            ->selectRaw("DATE_FORMAT(table_bookings.starts_at, '%a %e %b %Y, %H:%i') as from_text")
            ->selectRaw("DATE_FORMAT(table_bookings.ends_at, '%a %e %b %Y, %H:%i') as until_text")
            // One word for what the row means tonight, derived the same way the picker
            // derives it: `released` was written by hand, `freed` fell out of the order
            // dying, `holding` is a table nobody else can book.
            ->selectRaw(
                'CASE'
                . " WHEN table_bookings.status = ? THEN 'released'"
                . ' WHEN orders.deleted_at IS NOT NULL OR orders.status = ? THEN \'freed\''
                . " ELSE 'holding' END as state",
                [TableBooking::STATUS_RELEASED, Order::STATUS_CANCELLED]
            )
            // Chronological, always. The From/Until columns are formatted text (see above),
            // and text like "Fri 4 Sep" sorts by alphabet, not by evening — so the columns
            // are not sortable in the schema and the order is pinned here instead, which is
            // the one order a reservations list is ever read in.
            ->orderBy('table_bookings.starts_at');

        $this->applyStateFilter($query, $filters['state'] ?? null);
        $this->applyDayFilter($query, $filters['day'] ?? null);

        $outletId = $filters['outlet_id'] ?? null;

        if (is_scalar($outletId) && (int) $outletId > 0) {
            $query->where('outlet_tables.outlet_id', (int) $outletId);
        }

        return $query;
    }

    /**
     * Narrow to one of the three derived states.
     *
     * The filter re-states the CASE above in WHERE terms rather than wrapping the query and
     * filtering on the alias, because MySQL cannot use an alias in WHERE and a HAVING clause
     * would fight the paginator's COUNT.
     */
    protected function applyStateFilter($query, mixed $state): void
    {
        if (! is_scalar($state)) {
            return;
        }

        match ((string) $state) {
            'holding' => $query
                ->where('table_bookings.status', TableBooking::STATUS_BOOKED)
                ->whereNull('orders.deleted_at')
                ->where('orders.status', '!=', Order::STATUS_CANCELLED),
            'released' => $query->where('table_bookings.status', TableBooking::STATUS_RELEASED),
            'freed' => $query
                ->where('table_bookings.status', TableBooking::STATUS_BOOKED)
                ->where(fn ($q) => $q
                    ->whereNotNull('orders.deleted_at')
                    ->orWhere('orders.status', Order::STATUS_CANCELLED)),
            default => null,
        };
    }

    /**
     * Tonight, the week ahead, or what has already happened.
     *
     * Day boundaries are drawn on the SHOP's clock. A branch may keep its own timezone, and
     * the kitchen board buckets on the branch clock — but one list mixing branches has to pick
     * one midnight, and the shop's is the one the operator reading the screen lives in.
     * "Overlapping" rather than "starting", so a long sitting that began before midnight is
     * still on today's list while it is still occupying a table.
     */
    protected function applyDayFilter($query, mixed $day): void
    {
        if (! is_scalar($day) || (string) $day === '' || (string) $day === 'all') {
            return;
        }

        try {
            $timezone = app(ServiceWindowRepository::class)->timezone();
        } catch (\Throwable $e) {
            $timezone = config('app.timezone', 'UTC');
        }

        $todayStart = Carbon::now($timezone)->startOfDay();

        // Wall-clock strings, matching how `TableReservation` writes the columns: the shop's
        // own clock, no conversion on read.
        $fmt = fn (Carbon $t) => $t->format('Y-m-d H:i:s');

        match ((string) $day) {
            'today' => $query
                ->where('table_bookings.starts_at', '<', $fmt($todayStart->copy()->addDay()))
                ->where('table_bookings.ends_at', '>', $fmt($todayStart)),
            'week' => $query
                ->where('table_bookings.starts_at', '<', $fmt($todayStart->copy()->addDays(7)))
                ->where('table_bookings.ends_at', '>', $fmt($todayStart)),
            'past' => $query->where('table_bookings.ends_at', '<=', $fmt($todayStart)),
            default => null,
        };
    }

    public function find($id)
    {
        return TableBooking::with(['table', 'order'])->findOrFail($id);
    }

    public function getOptions(array $columns = [])
    {
        return [
            'state' => [
                ['title' => 'Holding a table', 'value' => 'holding'],
                ['title' => 'Freed by cancellation', 'value' => 'freed'],
                ['title' => 'Released by hand', 'value' => 'released'],
            ],
            'booking_branch' => \Theme\Backend\Models\Outlet::query()
                ->orderByDesc('is_default')
                ->ordered()
                ->get()
                ->map(fn ($o) => [
                    'title' => $o->getTranslation('title', app()->getLocale(), false) ?: $o->slug,
                    'value' => $o->id,
                ])->all(),
        ];
    }

    public function savePageData(string $slug, array $data)
    {
        return match ($slug) {
            'release' => $this->release($data),
            default   => ['message' => 'No save handler defined for this page.'],
        };
    }

    /**
     * Give a table back by hand, without touching the order.
     *
     * The point of a manual release is the cancellation phone call: the party is not coming,
     * the order may already be paid or cooked, and cancelling it to free the table would be
     * the wrong write twice over. Writing {@see TableBooking::STATUS_RELEASED} frees the span
     * for the next customer the moment the row commits — the overlap check reads `holding()`,
     * which asks status first.
     *
     * Releasing a row that no longer holds anything is answered in words, not with an error:
     * the button and this handler race the order's own cancellation, and losing that race is
     * a fact to report, not a fault.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function release(array $data): array
    {
        $booking = TableBooking::query()->find($data['id'] ?? null);

        if ($booking === null) {
            return ['message' => 'That booking no longer exists.'];
        }

        if ($booking->status === TableBooking::STATUS_RELEASED) {
            return ['message' => 'That booking was already released.'];
        }

        // `update()` so `LogsSystemActivity` records who freed the table and when — the fact
        // most likely to be asked about when the party turns up anyway.
        $booking->update(['status' => TableBooking::STATUS_RELEASED]);

        return ['message' => 'Table released. The span is free for the next booking.'];
    }
}
