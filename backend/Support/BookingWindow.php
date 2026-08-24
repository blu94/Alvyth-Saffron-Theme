<?php

namespace Theme\Backend\Support;

/**
 * How long a booking holds a table (register O19, phase 2).
 *
 * **One place decides, and this is it.** The operator's instruction was a unified place to
 * customise booking durations, so the whole answer lives in one settings block — a shop default
 * and, for a branch that genuinely differs, an override in that same block. Nothing reads a
 * duration from an outlet row, a table row or a hard-coded constant, which is what stops the
 * question being answerable in two places that disagree.
 *
 * The default is **60 minutes**, the operator's own figure. It is not a neutral choice: the
 * duration decides how many sittings an evening holds, so a shop that never touches this setting
 * turns each table twice between 7 and 9 rather than once.
 *
 * **Two figures, one block.** How long a booking holds its table, and how far ahead one may be
 * made — both shop-wide with a per-branch override, because a kitchen that preps a day earlier
 * than its neighbours needs its own answer to the second while sharing the first.
 *
 * **Today is never bookable, and that is a design decision rather than a default.** The minimum
 * lead is a whole number of days with a floor of 1, so the soonest bookable table is tomorrow.
 * Everybody dining today is therefore a walk-in, and a reservation can never be in dispute with
 * somebody who has already sat down — which is the case that makes a floor unmanageable, and the
 * reason the walk-in-versus-booked-table question stopped needing an answer.
 */
class BookingWindow
{
    /** The fallback when a shop has never touched the setting. */
    public const DEFAULT_MINUTES = 60;

    /** Bounds, matching the schema's own rules so a hand-edited settings file cannot escape them. */
    public const MIN_MINUTES = 15;
    public const MAX_MINUTES = 480;

    /**
     * Minutes a booking at `$outletId` holds its table.
     *
     * The branch override wins where one is authored; otherwise the shop default; otherwise 60.
     * A null outlet — a shop with no branches at all — takes the default, which is the same
     * answer it would get from an unlisted branch.
     */
    public static function minutesFor(?int $outletId = null, ?array $settings = null): int
    {
        $settings = $settings ?? ThemeSettings::all();

        $override = static::overrideFor($outletId, $settings);

        if ($override !== null) {
            return $override;
        }

        return static::clamp($settings['booking_duration_minutes'] ?? null) ?? self::DEFAULT_MINUTES;
    }

    /** The soonest a booking may be made, in days from today. Never less than 1. */
    public const DEFAULT_DAYS_AHEAD = 1;

    public const MAX_DAYS_AHEAD = 365;

    /**
     * How many days ahead the earliest bookable table at `$outletId` sits.
     *
     * Resolved exactly as the duration is — branch override, then shop default, then the
     * constant — so an operator learns one rule and it holds for both figures.
     *
     * **Floored at 1 rather than 0.** A shop that types 0 is asking for same-day bookings, and
     * the whole reason this exists is that a table reserved for 8pm tonight cannot be told apart
     * from the party sitting at it now. Clamping rather than obeying keeps that guarantee out of
     * the reach of a mistyped settings field.
     */
    public static function daysAheadFor(?int $outletId = null, ?array $settings = null): int
    {
        $settings = $settings ?? ThemeSettings::all();

        $override = static::branchValue($outletId, $settings, 'min_days_ahead', 1, self::MAX_DAYS_AHEAD);

        if ($override !== null) {
            return $override;
        }

        return static::clamp($settings['booking_min_days_ahead'] ?? null, 1, self::MAX_DAYS_AHEAD)
            ?? self::DEFAULT_DAYS_AHEAD;
    }

    /**
     * The branch's own figure, or null when it has none.
     *
     * Rows are the repeater's, so every value arrives as whatever the browser posted — hence the
     * loose comparison on the outlet id and the clamp on the minutes. A row naming a branch that
     * has since been deleted simply never matches, which is the right outcome: it is stale
     * configuration, not a reason to refuse a booking.
     */
    protected static function overrideFor(?int $outletId, array $settings): ?int
    {
        return static::branchValue($outletId, $settings, 'minutes', self::MIN_MINUTES, self::MAX_MINUTES);
    }

    /**
     * One field from one branch's override row.
     *
     * A single row per branch carries both figures, so a shop that wants a longer sitting AND a
     * later lead at one branch fills in one row rather than hunting two lists. Either cell may be
     * left blank, and a blank falls through to the shop default rather than to zero.
     */
    protected static function branchValue(?int $outletId, array $settings, string $key, int $min, int $max): ?int
    {
        if ($outletId === null) {
            return null;
        }

        $rows = $settings['booking_duration_overrides'] ?? [];

        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || (int) ($row['outlet_id'] ?? 0) !== $outletId) {
                continue;
            }

            $value = static::clamp($row[$key] ?? null, $min, $max);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A usable number of minutes, or null.
     *
     * Blank, non-numeric and out-of-range all read as "not set" rather than as zero. A zero-length
     * booking would hold no table at all and every overlap check against it would pass, so a
     * mistyped setting must fall back to a real duration rather than silently switching
     * reservations off.
     */
    protected static function clamp(mixed $value, ?int $min = null, ?int $max = null): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $min = $min ?? self::MIN_MINUTES;
        $max = $max ?? self::MAX_MINUTES;

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            return null;
        }

        return $number;
    }
}
