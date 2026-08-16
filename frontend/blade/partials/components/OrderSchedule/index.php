<?php

namespace Theme\Components;

use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Theme\Backend\Models\ServiceWindow;
use Theme\Backend\Support\ThemeSettings;

/**
 * The "When do you want it" block on the cart — ASAP, or a slot the shop is actually open for.
 *
 * The slot list is derived from the shop's own Service Hours in the shop's timezone: a dated
 * closure removes its day, a dated override replaces that day's hours, and today only offers
 * times far enough ahead to cook for. The chosen value rides to the server as an ordinary
 * `[data-checkout-field]` — core's Cart section collects every one of those into
 * `plugin_fields`, and checkout persists the validated bag to `order.meta.checkout_fields`
 * (spec §14 item 2, register O3) — which is where the Kitchen Queue reads it back.
 *
 * > This is PRESENTATION, NOT ENFORCEMENT. The picker only offers in-hours times, but the
 * > server does not yet refuse a hand-crafted request naming 3 a.m. (register O5). The
 * > Restaurant tab's hint says so to the operator.
 *
 * Renders nothing when the operator turned scheduling off, when no whole-shop hours are
 * authored (no honest slot list can be derived from nothing), or when every day in reach is
 * closed — an ASAP-only shop simply has no block here, rather than a picker with one choice.
 */
class OrderSchedule
{
    /** Minutes of lead time before the first offerable slot today. */
    protected const MIN_LEAD_MINUTES = 30;

    /** Minutes between offered times — the interval delivery platforms train customers on. */
    protected const SLOT_MINUTES = 30;

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = ThemeSettings::all();

        if (! filter_var($settings['scheduling_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        $daysAhead = min(7, max(1, (int) ($settings['scheduling_days_ahead'] ?? 3)));

        // A missing table (a half-run migration, a stale import) must not take the cart page
        // down over a picker. Degrade to ASAP-only by rendering nothing.
        try {
            $days = $this->days($daysAhead, config('app.timezone', 'UTC'));
        } catch (\Throwable $e) {
            report($e);

            return '';
        }

        if ($days === []) {
            return '';
        }

        return View::make($themeViewPath, [
            'locale' => $locale,
            // One variable through @json — Blade's compileJson splits on top-level commas,
            // so a multi-key literal at the call site is the bug this theme already fixed once.
            'payload' => [
                'days'   => $days,
                'labels' => [
                    'asap'     => __('As soon as possible'),
                    'schedule' => __('Choose a time'),
                    'day'      => __('Day'),
                    'time'     => __('Time'),
                ],
            ],
        ])->render();
    }

    /**
     * The days worth offering, each with its date, a human label and its 30-minute slots.
     *
     * Public, and takes `$from`, so the slot arithmetic is testable against a fixed clock —
     * "today's earlier slots are gone" is exactly the kind of claim that passes by accident
     * when the test runs in the morning and fails at night.
     *
     * @return array<int, array{date: string, label: string, slots: array<int, string>}>
     */
    public function days(int $daysAhead, string $timezone, ?Carbon $from = null): array
    {
        $now = ($from ? $from->copy() : Carbon::now())->setTimezone($timezone);

        // No whole-shop weekly hours at all means the shop has not configured them; unlike
        // the Store Status banner (which reports "open" so nothing looks broken), a picker
        // must not invent times nobody entered.
        $hasHours = ServiceWindow::recurring()
            ->where('status', 'active')
            ->whereNull('scope_id')
            ->exists();

        if (! $hasHours) {
            return [];
        }

        $days = [];

        for ($i = 0; $i < $daysAhead; $i++) {
            $day   = $now->copy()->addDays($i)->startOfDay();
            $slots = [];

            foreach ($this->windowsFor($day) as [$opens, $closes]) {
                $slot = Carbon::parse($day->toDateString() . ' ' . $opens, $timezone);
                $end  = Carbon::parse($day->toDateString() . ' ' . $closes, $timezone);

                // The last slot sits one interval before close — an order for the minute
                // the shutter comes down is a slot in name only.
                while ($slot->copy()->addMinutes(self::SLOT_MINUTES) <= $end) {
                    if ($i > 0 || $slot->gte($now->copy()->addMinutes(self::MIN_LEAD_MINUTES))) {
                        $slots[] = $slot->format('H:i');
                    }

                    $slot->addMinutes(self::SLOT_MINUTES);
                }
            }

            if ($slots === []) {
                continue;
            }

            sort($slots);

            $days[] = [
                'date'  => $day->toDateString(),
                'label' => $i === 0 ? __('Today') : ($i === 1 ? __('Tomorrow') : $day->translatedFormat('D j M')),
                'slots' => array_values(array_unique($slots)),
            ];
        }

        return $days;
    }

    /**
     * The orderable spans for one date, as `[opens, closes]` pairs of `H:i` strings.
     *
     * A dated entry wins its date outright — the same precedence the Store Status banner and
     * the hours table apply — and `closesTheDay()` also covers an override missing either
     * time, so half a window never becomes a slot list.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function windowsFor(Carbon $day): array
    {
        $exception = ServiceWindow::exceptions()
            ->where('status', 'active')
            ->whereDate('date', $day->toDateString())
            ->first();

        if ($exception) {
            if ($exception->closesTheDay()) {
                return [];
            }

            return [[
                substr((string) $exception->opens_at, 0, 5),
                substr((string) $exception->closes_at, 0, 5),
            ]];
        }

        return ServiceWindow::recurring()
            ->where('status', 'active')
            ->whereNull('scope_id')
            ->where('day_of_week', (int) $day->dayOfWeek)
            ->orderBy('opens_at')
            ->get()
            ->map(fn ($w) => [
                substr((string) $w->opens_at, 0, 5),
                substr((string) $w->closes_at, 0, 5),
            ])
            ->all();
    }
}
