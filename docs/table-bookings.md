# Table Bookings — tonight at a glance

The Saffron theme adds one screen for the dining room: **Table Bookings**, in the theme's own
sidebar group. It answers the question a counter asks all evening — *which tables are spoken
for, from when to when, and for how many* — and it holds the one control bookings need that
orders do not: giving a table back without touching the order it came from.

Bookings are made by customers, at the checkout, when they choose **Dine in** and pick a time.
Nothing is created from this screen, and nothing here can be edited — a reservation is part of
the customer's order, written under a lock so two parties can never be given the same table.
This screen is for *seeing* the evening and, occasionally, for cutting a table loose.

![Tonight's bookings](/docs/table-bookings/01-list.webp)

Each row is one held span: the branch, the table, the party size, the times, the order that
made it, and a **State**:

| State | What it means |
|---|---|
| **Holding** | The table is taken for that span. Nobody else can book it |
| **Freed by cancellation** | The order behind the booking was cancelled, so the table freed itself. Nothing to do — the row stays for the record |
| **Released by hand** | Somebody pressed Release. The order still stands; only the table came back |

The list opens on **Today** and reads top to bottom in the order the evening will happen.
The filters swap the view: the next seven days, the past, one branch, or one state.

![Filtering the list](/docs/table-bookings/02-filters.webp)

## Releasing a table

The party phones to say they are not coming — but their order may be paid, or already cooked,
so cancelling it would be wrong twice over. **Release** is for exactly this: the order stays
exactly as it is, and the table's span becomes bookable again the moment you confirm.

![Confirming a release](/docs/table-bookings/03-release.webp)

The button only appears on a booking that is still holding its table. A release is written to
the activity log — who freed the table, and when — because that is the fact most likely to be
asked about if the party turns up anyway.

**You never need Release to clean up after a cancellation.** A cancelled order frees its table
by itself, immediately, everywhere. And an order that was never paid keeps its table on
purpose: *not paid yet* is the normal state of a perfectly good booking at a shop that takes
payment at the door.

## Opening the order

The receipt button on each row opens the order behind the booking — the place to see who is
coming, what they pre-ordered, and what they paid. Cancelling the order there frees the table
too; Release is only for freeing the table *without* doing that.
