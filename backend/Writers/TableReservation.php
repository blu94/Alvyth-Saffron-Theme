<?php

namespace Theme\Backend\Writers;

use App\Contracts\Storefront\OrderWriter;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Theme\Backend\Models\OutletTable;
use Theme\Backend\Models\TableBooking;
use Theme\Backend\Repositories\ServiceWindowRepository;
use Theme\Backend\Support\BookingWindow;
use Theme\Backend\Support\ThemeSettings;

/**
 * Holds a dining table for a booked order (register O19, phase 2).
 *
 * **This is the only thing that makes a booking real.** The customer's page offers times and
 * tables as a convenience; a page can be reloaded, raced or bypassed entirely by posting
 * straight to checkout. The allocation below is the truth, and it runs where truth about a
 * finite resource has to run — inside the order's own transaction, through core's
 * {@see OrderWriter} seam.
 *
 * ## The race, and the one statement that settles it
 *
 * Two customers booking table 7 for 19:00 a millisecond apart both find it free if the check
 * and the write are separate steps. So the table's own row is locked first
 * (`SELECT ... FOR UPDATE`), and the overlap is re-read *behind that lock*: the second caller
 * waits on the first's lock and then sees the booking the first just wrote. It either finds the
 * table free — and has already taken it — or finds it taken, and refusing is the outcome.
 * Exactly the shape `OrderRepository::reduceStock()` uses for the last portion of a dish, for
 * exactly the same reason.
 *
 * Locking the **table** rather than the booking rows is deliberate: there is no booking row to
 * lock when the table is free, and a gap lock on an empty range is what deadlocks two
 * simultaneous first-ever writers (the trap `SequenceAllocator` documents).
 *
 * ## What it refuses, and what it ignores
 *
 * A `ValidationException` rolls the whole order back — no order, no items, no stock taken, no
 * gateway session — and checkout answers 422 with the sentence the customer reads. It refuses a
 * table already held, a table that cannot seat the party, a same-day booking and a table that
 * does not belong to the branch the order names.
 *
 * It **ignores** every order that is not a dine-in booking: a delivery, a collection, and a
 * walk-in diner ordering from a table they are already sitting at. That last one is the rule the
 * operator set — today is never bookable — and it is why a walk-in can never collide with a
 * reservation.
 */
class TableReservation implements OrderWriter
{
    public function write(Order $order): void
    {
        $fields = $order->meta['checkout_fields'] ?? [];

        if (! is_array($fields)) {
            return;
        }

        // Not a diner at all, or a walk-in ordering from the table they are sitting at. Either
        // way there is nothing to hold: a walk-in has already taken the table by being in it.
        if (($fields['dining'] ?? null) !== 'dine_in') {
            return;
        }

        $settings = ThemeSettings::all();

        // A shop that does not take bookings never reaches the rest of this, whatever a posted
        // field claims — the switch is the shop's answer, not the customer's.
        if (! filter_var($settings['accept_table_bookings'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $scheduledAt = trim((string) ($fields['scheduled_at'] ?? ''));

        // Empty is the picker's own spelling of "as soon as possible", which for a diner means
        // they are here now. Nothing is reserved.
        if ($scheduledAt === '') {
            return;
        }

        $label = trim((string) ($fields['table_number'] ?? ''));

        if ($label === '') {
            $this->refuse(__('Please choose which table you would like.'));
        }

        $outletId = (int) ($fields['outlet_id'] ?? 0) ?: null;

        [$startsAt, $endsAt] = $this->span($scheduledAt, $outletId, $settings);

        $table = $this->tableFor($outletId, $label);

        $covers = (int) ($fields['covers'] ?? 0) ?: null;

        if ($covers && $table->seats && $covers > $table->seats) {
            $this->refuse(__('That table seats :seats. Please choose one that fits :covers.', [
                'seats'  => $table->seats,
                'covers' => $covers,
            ]));
        }

        $this->hold($order, $table, $startsAt, $endsAt, $covers);
    }

    // ── the allocation ──────────────────────────────────────────────────────────

    /**
     * Take the table, or refuse — under the table's own lock.
     *
     * Runs inside `OrderRepository::create()`'s transaction, so the lock is held until the
     * order commits and a second booking cannot slip between the check and the insert.
     */
    protected function hold(Order $order, OutletTable $table, Carbon $startsAt, Carbon $endsAt, ?int $covers): void
    {
        // The lock. `lockForUpdate()` on a row that exists takes a plain record lock, which is
        // what serialises two customers wanting the same table and nothing else.
        OutletTable::query()->whereKey($table->id)->lockForUpdate()->first();

        $taken = TableBooking::query()
            ->holding()
            ->overlapping($table->id, $startsAt, $endsAt)
            ->exists();

        if ($taken) {
            $this->refuse(__('Table :table is already booked at that time. Please choose another table or another time.', [
                'table' => $table->label,
            ]));
        }

        TableBooking::create([
            'order_id'        => $order->id,
            'outlet_table_id' => $table->id,
            'starts_at'       => $startsAt,
            'ends_at'         => $endsAt,
            'status'          => TableBooking::STATUS_BOOKED,
            'covers'          => $covers,
        ]);
    }

    // ── resolving what was asked for ────────────────────────────────────────────

    /**
     * The span this booking holds, and the rule that today is never part of it.
     *
     * Read in the **shop's** timezone, the one every other hours decision here uses, so a
     * customer in another timezone books the table's evening rather than their own.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function span(string $scheduledAt, ?int $outletId, array $settings): array
    {
        $timezone = app(ServiceWindowRepository::class)->timezone();

        try {
            $startsAt = Carbon::parse($scheduledAt, $timezone);
        } catch (\Throwable $e) {
            // A checkout field is client-supplied. An unparseable one is refused rather than
            // guessed at: guessing would book a table at a time nobody chose.
            $this->refuse(__('That booking time could not be read. Please choose a time again.'));
        }

        $earliest = Carbon::now($timezone)
            ->startOfDay()
            ->addDays(BookingWindow::daysAheadFor($outletId, $settings));

        if ($startsAt->lt($earliest)) {
            $this->refuse(__('Tables can be booked from :date onwards. Today is for walk-in guests.', [
                'date' => $earliest->translatedFormat('j M'),
            ]));
        }

        return [$startsAt, $startsAt->copy()->addMinutes(BookingWindow::minutesFor($outletId, $settings))];
    }

    /**
     * The table the customer named, at the branch the order names.
     *
     * Matched by **label within the branch**, because that is what the customer was shown and
     * what `table_number` has always carried — an id would break every order already placed.
     * A label naming no table at that branch is refused rather than ignored: silently taking no
     * booking is how a customer ends up with a confirmed order and no table.
     */
    protected function tableFor(?int $outletId, string $label): OutletTable
    {
        $table = OutletTable::query()
            ->active()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->first();

        if (! $table) {
            $this->refuse(__('We could not find table :table at that branch. Please choose your table again.', [
                'table' => $label,
            ]));
        }

        return $table;
    }

    /** Refuse the order, in words the customer reads. */
    protected function refuse(string $message): never
    {
        throw ValidationException::withMessages(['checkout' => [$message]]);
    }
}
