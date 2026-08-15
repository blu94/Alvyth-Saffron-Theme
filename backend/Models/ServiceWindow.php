<?php

namespace Theme\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * When the shop, or one menu section, is orderable.
 *
 * Two kinds of row live here, because an operator holds one concept — opening hours, and the
 * days that differ from them:
 *
 *   - `recurring` — a weekly window: `day_of_week` + `opens_at` + `closes_at`.
 *   - `exception` — a dated override: `date` + `exception_type`, optionally with hours.
 *
 * They were two tables and two admin modules, which meant deciding which screen a Tuesday
 * public holiday belonged to before you could enter it. The columns the other kind does not
 * use stay null; `kind` carries what the table name used to.
 *
 * Times are the shop's local wall-clock. Everything that compares them must do so in the
 * shop's timezone, never the server's — see `Theme\Backend\Repositories\ServiceWindowRepository`.
 */
class ServiceWindow extends Model
{
    use SoftDeletes, LogsSystemActivity;

    use HasTranslations;

    public const KIND_RECURRING = 'recurring';
    public const KIND_EXCEPTION = 'exception';

    public $translatable = ['reason'];

    protected $fillable = [
        'kind',
        'scope_type',
        'scope_id',
        'day_of_week',
        'date',
        'opens_at',
        'closes_at',
        'mode',
        'exception_type',
        'reason',
        'status',
        'orders',
        'data',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'date'        => 'date',
        'reason'      => 'json',
        'orders'      => 'integer',
        'data'        => 'array',
    ];

    public const DAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    public function dayName(): string
    {
        return self::DAYS[$this->day_of_week] ?? 'Unknown';
    }

    /**
     * Whether a shop-local wall-clock time falls inside this window.
     *
     * `H:i` string comparison is correct here and cheaper than parsing: both operands are
     * zero-padded 24-hour times, so lexical and chronological order agree. A window that
     * closes past midnight is not expressible in one row — author two rows, one per day,
     * which is also how split service is expressed.
     */
    public function covers(string $localTime): bool
    {
        $opens  = substr((string) $this->opens_at, 0, 5);
        $closes = substr((string) $this->closes_at, 0, 5);
        $now    = substr($localTime, 0, 5);

        return $now >= $opens && $now < $closes;
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_RECURRING);
    }

    public function scopeExceptions(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_EXCEPTION);
    }

    public function isException(): bool
    {
        return $this->kind === self::KIND_EXCEPTION;
    }

    /**
     * Whether this dated override closes the shop outright.
     *
     * `closed` is explicit, but a `custom` or `open` row missing either time is treated the
     * same way: half a window is not a window, and guessing the other end would advertise
     * hours nobody entered.
     */
    public function closesTheDay(): bool
    {
        if ($this->exception_type === 'closed') {
            return true;
        }

        return blank($this->opens_at) || blank($this->closes_at);
    }
}
