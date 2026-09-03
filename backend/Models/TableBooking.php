<?php

namespace Theme\Backend\Models;

use App\Models\Order;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One table, held for one party, over one span (register O19).
 *
 * No `SoftDeletes`: a booking is released by moving its `status`, never by disappearing. A
 * soft-deleted row would still satisfy the overlap query unless every reader remembered to
 * exclude it, and the one that forgot would hold a table for a cancelled order for ever.
 */
class TableBooking extends Model
{
    use LogsSystemActivity;

    /** Holding its table. Any other status has released it. */
    public const STATUS_BOOKED = 'booked';

    /** Released — the order was cancelled, or the booking was given up. */
    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'order_id',
        'outlet_table_id',
        'starts_at',
        'ends_at',
        'status',
        'covers',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'covers'    => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(OutletTable::class, 'outlet_table_id');
    }

    /**
     * Only the bookings actually holding a table.
     *
     * **A booking holds its table while its status is `booked` AND the order behind it still
     * stands.** The second half is derived rather than stored, and that is the whole design: a
     * cancelled order's table frees itself the instant the order is cancelled, everywhere, without
     * anything having to remember to write {@see self::STATUS_RELEASED}. Before this, nothing in
     * the theme or in core ever wrote that constant — so a cancelled or refunded order kept its
     * table for the whole sitting, refusing every other customer that table and time, with no
     * screen anywhere to release it.
     *
     * Deriving it also survives the paths a hook would miss: a cancellation from the admin form,
     * from the status-transition endpoint, from a payment failure, or from a future caller nobody
     * has written yet. `released` is still worth writing for history and reporting when there is
     * something to write it from, but correctness no longer waits on that.
     *
     * **An unpaid order keeps its table, deliberately.** Pay-on-arrival is a shipped feature here,
     * so "not paid yet" is the normal state of a perfectly good dine-in booking and must never
     * release. An order abandoned mid-payment sits `pending` for ever and keeps its table; that is
     * core's to fix — orders do not expire — and guessing at a grace period here would take tables
     * away from the customers who chose to pay at the door.
     *
     * The column is qualified because the picker reads this through a join with `outlet_tables`,
     * which has a `status` column of its own: an unqualified clause is ambiguous, MySQL refuses the
     * whole query, and the picker's fail-open catch then offers every table as free. That trap has
     * already been paid for once — it is why the picker used to write this filter out by hand
     * instead of calling the scope, and why the rule then existed in two places to drift apart.
     *
     * **A JOIN, not a `whereExists`, and that is load-bearing rather than stylistic.** The booking
     * writer runs this behind `lockForUpdate()`, and MySQL does **not** extend a locking read into
     * a nested subquery unless the subquery says so itself — so an `EXISTS (SELECT ... FROM
     * orders)` would be answered from the transaction's stale snapshot even while the outer query
     * read fresh. A competing order committed a moment ago would look like no order at all, its
     * booking would look released, and the table would be handed out twice: this rule would have
     * quietly re-opened the very race the lock exists to close. A joined table is scanned by the
     * outer statement, so `FOR UPDATE` locks and refreshes it too. The first draft used `EXISTS`
     * and `TableBookingTest::the_overlap_check_sees_a_booking_committed_after_this_transaction_began`
     * is what caught it.
     */
    public function scopeHolding(Builder $query, string $table = 'table_bookings'): Builder
    {
        return $query
            ->where($table . '.status', self::STATUS_BOOKED)
            ->join('orders', 'orders.id', '=', $table . '.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', Order::STATUS_CANCELLED);
    }

    /**
     * Bookings on `$tableId` whose span overlaps `[$startsAt, $endsAt)`.
     *
     * **Half-open on purpose.** A booking ending at 20:00 and one starting at 20:00 do not
     * overlap — that is a table turning, and the commonest thing a restaurant does all evening.
     * Written as `starts_at < end AND ends_at > start`, which is the standard interval test and
     * is what the composite index on `(outlet_table_id, starts_at)` serves; the naive
     * "starts between" form misses a long booking that began before the window and is still
     * running through it.
     */
    public function scopeOverlapping(Builder $query, int $tableId, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): Builder
    {
        return $query->where('outlet_table_id', $tableId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }
}
