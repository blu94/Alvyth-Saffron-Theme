<?php

namespace Theme\Backend\Guards;

use App\Contracts\Storefront\CheckoutGuard;
use Carbon\Carbon;
use Theme\Backend\Repositories\ServiceWindowRepository;
use Theme\Backend\Support\ThemeSettings;

/**
 * Refuses an order the shop's own Service Hours say it cannot take.
 *
 * Declared in `manifest.json` under `checkout.guards`; core constructs it and asks before an
 * order row exists. Until this existed the hours were **presentation** — the Store Status
 * banner said Closed, the menu greyed out, the time picker offered only in-hours slots, and
 * `POST /api/storefront/checkout` accepted anything that posted directly, so a shop could take
 * an order at 3 a.m. that nobody would ever cook (spec §14 item 6, register O5).
 *
 * Two refusals, and they are separate questions:
 *
 * 1. **An ASAP order while the shop is closed.** Judged on *now*, in the shop's own wall clock.
 * 2. **A scheduled slot outside the target day's window.** Judged on *that day's* hours, never
 *    today's — spec §12 case 4. Ordering Sunday lunch on a Saturday night is the normal case,
 *    and validating it against Saturday would refuse it.
 *
 * Every answer comes from `ServiceWindowRepository::hoursForDate()`, which is also what the
 * banner and the picker read. That is the point: a refusal that disagrees with the page the
 * customer just used would tell them they may order and then turn them away.
 *
 * **What it deliberately does not refuse.** A time inside a window but off the picker's
 * half-hour grid (11:07) is accepted — the grid is a convenience, not a rule the kitchen keeps,
 * and refusing it would reject an order the shop can cook. `mode` on a window
 * (delivery/pickup/both) is not consulted either, because the theme has no ordering-mode
 * control yet; that arrives with register O4, and this is where it will be read.
 *
 * **It never throws to refuse.** Core treats a guard that throws as no opinion and lets the
 * order through, so anything this class wants to stop it must *return* — including a value it
 * could not parse.
 */
class ServiceWindowGuard implements CheckoutGuard
{
    /** The format the cart's picker posts, and the one the kitchen ticket reads back. */
    private const FORMAT = 'Y-m-d H:i';

    public function __construct(
        protected ServiceWindowRepository $windows
    ) {
    }

    public function check(array $cart, array $fields, array $context = []): ?string
    {
        $timezone = $this->windows->timezone();
        $now      = Carbon::now($timezone);

        // How the order is being fulfilled, so a window marked "delivery only" can actually
        // refuse a collection at that hour. Absent reads as delivery, which is what core does
        // and what every order placed before ordering modes existed was.
        $mode = $context['fulfillment_type'] ?? 'delivery';
        $mode = in_array($mode, ['delivery', 'pickup'], true) ? $mode : 'delivery';

        $scheduled = trim((string) ($fields['scheduled_at'] ?? ''));

        // Scheduling turned off means every order is ASAP, so a `scheduled_at` that arrived
        // anyway is judged as one. Honouring it instead would let a hand-crafted request buy a
        // slot on a shop that does not offer slots — and "are we open now" is the stricter of
        // the two rules, which is the right direction for a field nobody should have sent.
        if (! ThemeSettings::bool('scheduling_enabled', true)) {
            $scheduled = '';
        }

        return $scheduled === ''
            ? $this->refuseIfClosedNow($timezone, $now, $mode)
            : $this->refuseIfOutsideHours($scheduled, $timezone, $now, $mode);
    }

    /** An ASAP order the shop cannot start on: judged on now, for this mode. */
    private function refuseIfClosedNow(string $timezone, Carbon $now, string $mode): ?string
    {
        $state = $this->windows->openState($timezone, $now);

        if ($state['open'] ?? true) {
            // Open — but possibly not for *this* mode. A shop that takes collections all day
            // and delivers only at lunch is one row marked `delivery`, and until the order
            // carried a fulfilment type there was nothing to compare it against.
            return $this->refuseIfModeNotServedNow($timezone, $now, $mode);
        }

        // The operator's own wording where they wrote one — a holiday's reason is more use
        // than a generic sentence ("Closed for Hari Raya" tells the customer when to come
        // back). The Closed Shop Message is the same string the banner shows, so the page and
        // the refusal say one thing.
        return $state['reason']
            ?: $this->closedMessage()
            ?: __('We are closed right now, so this order cannot be placed.');
    }

    /**
     * Open right now, but is this mode served right now?
     *
     * Only reached when the shop is open, so a refusal here is specifically "we are not
     * delivering at this hour" rather than "we are shut" — a different sentence, because a
     * customer told the shop is closed while its lights are on will telephone.
     */
    private function refuseIfModeNotServedNow(string $timezone, Carbon $now, string $mode): ?string
    {
        $hours = $this->windows->hoursForDate($now);

        // No hours authored is no opinion, exactly as every other reader treats it.
        if ($hours['source'] === 'unconfigured') {
            return null;
        }

        $time = $now->format('H:i');

        foreach ($hours['spans'] as $span) {
            if ($time >= $span['opens'] && $time < $span['closes'] && $this->spanServes($span, $mode)) {
                return null;
            }
        }

        // A span covering now exists (openState said open) but none of them serve this mode.
        return $this->modeRefusal($mode);
    }

    /** A slot the shop is not open for: judged on the TARGET day, never today. */
    private function refuseIfOutsideHours(string $scheduled, string $timezone, Carbon $now, string $mode): ?string
    {
        $when = $this->parse($scheduled, $timezone);

        if ($when === null) {
            // Client-supplied, so a value in no recognisable shape is a hand-crafted request
            // rather than something the picker produced. Refused rather than ignored: falling
            // back to ASAP would silently accept the order the customer asked to schedule.
            return __('Please choose a delivery time from the list.');
        }

        if ($when->lt($now)) {
            return __('That time has already passed. Please choose another.');
        }

        // The same clamp the picker offers slots within — one implementation, on the object
        // both of them already hold, so the page and the refusal cannot disagree about how far
        // ahead a customer may book.
        $horizon = $this->windows->schedulingHorizon();

        // Beyond what the picker offers. Without this a request can name a valid weekday six
        // months out, which is in-hours and would be accepted — an order no kitchen will see.
        //
        // `copy()` on both sides because Carbon mutates: `$when->startOfDay()` rewrites the
        // very value the window check below reads, and every slot silently became midnight —
        // which refused a perfectly good 11:00 tomorrow. Caught by the test, not by review.
        if ($when->copy()->startOfDay()->gt($now->copy()->startOfDay()->addDays($horizon - 1))) {
            return __('We only take orders up to :days days ahead.', ['days' => $horizon]);
        }

        $hours = $this->windows->hoursForDate($when);

        // No hours authored at all is no opinion, exactly as the banner reads it.
        if ($hours['source'] === 'unconfigured') {
            return null;
        }

        $time = $when->format('H:i');

        $inAnySpan = false;

        foreach ($hours['spans'] as $span) {
            if ($time < $span['opens'] || $time >= $span['closes']) {
                continue;
            }

            $inAnySpan = true;

            if ($this->spanServes($span, $mode)) {
                return null;
            }
        }

        // Inside a span but none of them serve this mode: the shop is open then, just not for
        // this. Saying "we are not open at that time" there would be false and would send the
        // customer looking for a different hour when they should switch to collection.
        if ($inAnySpan) {
            return $this->modeRefusal($mode);
        }

        return $hours['reason']
            ?: __('We are not open at that time. Please choose another.');
    }

    /**
     * Does this span serve this fulfilment mode?
     *
     * `both`, and anything unrecognised, serves everything — an operator who never touched
     * Applies To must not have their shop narrowed by a column they did not know about.
     *
     * @param  array{opens:string, closes:string, mode?:string}  $span
     */
    private function spanServes(array $span, string $mode): bool
    {
        $serves = (string) ($span['mode'] ?? 'both');

        return $serves === $mode || ! in_array($serves, ['delivery', 'pickup'], true);
    }

    /** The sentence for "open, but not for this mode". */
    private function modeRefusal(string $mode): string
    {
        return $mode === 'pickup'
            ? __('We are not taking collection orders at that time. Please choose another time, or switch to delivery.')
            : __('We are not delivering at that time. Please choose another time, or switch to pickup.');
    }

    /**
     * The picker's value as a moment in the shop's timezone, or null when it is not one.
     *
     * Strict: `createFromFormat` alone accepts overflow (`2026-02-30` becomes 2 March), which
     * would let a date the shop is closed on be silently rewritten into one it is open on.
     * Re-formatting and comparing is what rejects it.
     */
    private function parse(string $value, string $timezone): ?Carbon
    {
        try {
            $when = Carbon::createFromFormat(self::FORMAT, $value, $timezone);
        } catch (\Throwable $e) {
            return null;
        }

        return $when && $when->format(self::FORMAT) === $value ? $when : null;
    }

    private function closedMessage(): string
    {
        $message = ThemeSettings::get('closed_message', '');

        if (is_array($message)) {
            $locale  = app()->getLocale();
            $message = $message[$locale] ?? $message['en'] ?? (count($message) ? reset($message) : '');
        }

        return trim((string) $message);
    }
}
