<?php

namespace Theme\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One table in one branch's dining room (register O19).
 *
 * A table is a physical object: it stands in exactly one room, it carries a name the staff say
 * out loud, and it seats a finite number of people. Each of those three facts is a column here,
 * and none of them was expressible while "tables" was a single shop-wide count.
 *
 * **What this model deliberately does not know is whether it is free.** Availability is a
 * property of a *moment*, not of a table, so it belongs to the booking rows phase 2 adds — a
 * `busy` flag here would be a cached answer to a question nobody has asked yet, and it would be
 * wrong the second a booking was cancelled by any path that forgot to clear it. Phase 1 asks
 * only "which tables does this branch have", which is what this answers.
 */
class OutletTable extends Model
{
    use SoftDeletes;
    use LogsSystemActivity;

    protected $fillable = [
        'outlet_id',
        'label',
        'seats',
        'status',
        'orders',
    ];

    protected $casts = [
        'seats'  => 'integer',
        'orders' => 'integer',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Only the tables a customer may be sent to.
     *
     * A table taken out of service — under repair, stacked away for the season — stops being
     * offered without being deleted, because deleting it would take its history with it.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * The operator's order, then the label.
     *
     * Ordered by `orders` first so a room can be listed the way it is walked rather than the way
     * it sorts, and by **id** as the tie-break rather than `label`: labels are strings, so a
     * plain sort puts "Table 10" above "Table 2" and a diner scanning for their own table finds
     * the list has reordered itself around them.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('`orders` IS NULL, `orders` ASC')->orderBy('id');
    }
}
