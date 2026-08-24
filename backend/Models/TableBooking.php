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

    /** Only the bookings actually holding a table. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_BOOKED);
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
