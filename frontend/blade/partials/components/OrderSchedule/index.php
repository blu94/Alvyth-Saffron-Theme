<?php

namespace Theme\Components;

use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Theme\Backend\Repositories\ServiceWindowRepository;
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
 * **While the shop is closed, ASAP is not offered at all.** There is nothing to be as soon as
 * possible about: the kitchen is shut, and the choice a customer can actually make is a future
 * slot. Every reader here — this picker, the Store Status banner, and the checkout guard —
 * takes its hours from `ServiceWindowRepository::hoursForDate()`, so what the page offers and
 * what the server accepts are the same answer. The server half is real now
 * (`Theme\Backend\Guards\ServiceWindowGuard`, spec §14 item 6, register O5): a hand-crafted
 * request naming 3 a.m. is refused rather than quietly cooked into an order nobody sees.
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

    /**
     * How many days get a one-tap button before the customer names a date instead.
     *
     * A week covers what a restaurant is actually asked for; past that the buttons stop being
     * a row and start being a wall, which is the point a date field reads better than chips.
     */
    protected const QUICK_DAYS = 7;

    public function __construct(
        protected ServiceWindowRepository $windows
    ) {
    }

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = ThemeSettings::all();

        if (! filter_var($settings['scheduling_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        // A missing table (a half-run migration, a stale import) must not take the cart page
        // down over a picker. Degrade to ASAP-only by rendering nothing.
        try {
            // The horizon lives on the repository because the checkout guard clamps by the
            // same number: offering a day the server would refuse is the one failure that
            // matters here, and two copies of a default is how it happens.
            $timezone = $this->windows->timezone();
            $horizon  = $this->windows->schedulingHorizon();
            $now      = Carbon::now($timezone);

            // The near days stay precomputed: they are the quick buttons, and the overwhelming
            // majority of orders are one of them. Everything past that is reached by naming a
            // date, resolved in the browser from the pattern below — a horizon of three months
            // is 90 dates, and asking the server per date is neither cheap nor possible (a
            // theme registers no endpoint).
            $days      = $this->days(min(self::QUICK_DAYS, $horizon), $timezone);
            $open      = (bool) ($this->windows->openState($timezone)['open'] ?? true);
            $pattern   = $this->windows->weeklyPattern();
            $overrides = $this->windows->exceptionsBetween($now, $now->copy()->addDays($horizon - 1));
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

                // Everything the browser needs to resolve a date the quick buttons do not
                // cover, with the same precedence the server applies: an override wins its
                // date, the weekly pattern fills the rest.
                'pattern'   => $pattern,
                // Cast so an empty map serialises as `{}` rather than `[]`. A lookup on an
                // array happens to return undefined too, but the browser is handed a
                // dictionary and should be given one whatever the shop has authored.
                'overrides' => (object) $overrides,
                'minDate'   => $now->toDateString(),
                // `$horizon - 1`, because today counts as the first of the horizon's days —
                // the same arithmetic `ServiceWindowGuard` applies. Offering `+ $horizon`
                // drew exactly one extra date, with real slots on it, that checkout then
                // refused as "up to N days ahead": the customer picked what the page showed
                // and was turned away with no way to see why. Sharing the setting was never
                // enough; the arithmetic around it has to match too.
                'maxDate'   => $now->copy()->addDays($horizon - 1)->toDateString(),
                // Shop-local, so "is this slot far enough ahead" is decided in the shop's
                // wall clock rather than the visitor's — a diner in another timezone must not
                // be offered a slot the kitchen has already passed.
                'nowLocal'  => $now->format('Y-m-d H:i'),
                'lead'      => self::MIN_LEAD_MINUTES,
                'step'      => self::SLOT_MINUTES,
                // Drives whether ASAP is offered at all. Sent as data rather than baked into
                // the markup because the same rendered page is served from cache to whoever
                // asks; the block decides on the value it was rendered with, and the server
                // decides again at checkout, which is the one that counts.
                'open'   => $open,
                'labels' => [
                    'asap'      => __('As soon as possible'),
                    'schedule'  => __('Choose a time'),
                    'day'       => __('Day'),
                    'time'      => __('Time'),
                    'closed'    => __('We are closed right now — choose a time below.'),
                    'otherDate' => __('Another date'),
                    'date'      => __('Date'),
                    // Qualifies the small time on each day button. Bare, "Today 14:00" reads
                    // as a delivery time rather than as the earliest one on offer.
                    'from'      => __('from'),
                    'upTo'      => __('up to'),
                    'shut'      => __('We are closed that day — try another date.'),
                    'noSlots'   => __('No times left that day — try another date.'),
                    'openThat'  => __('Open that day'),
                    'prevMonth' => __('Previous month'),
                    'nextMonth' => __('Next month'),
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
        if (! $this->windows->hasHours()) {
            return [];
        }

        $days = [];

        for ($i = 0; $i < $daysAhead; $i++) {
            $day   = $now->copy()->addDays($i)->startOfDay();
            $slots = [];

            foreach ($this->windows->hoursForDate($day)['spans'] as $span) {
                $slot = Carbon::parse($day->toDateString() . ' ' . $span['opens'], $timezone);
                $end  = Carbon::parse($day->toDateString() . ' ' . $span['closes'], $timezone);

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
}
