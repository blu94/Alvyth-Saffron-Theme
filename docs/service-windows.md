# Service Hours

Service Hours is **when your shop takes orders**: the hours you keep every week, and the
individual dates that differ from them — public holidays, early closes, one-off openings.
Both live in one list, because that is how you think about them: "we open 11 to 10, except
Hari Raya."

It also carries the **Kitchen Queue**, the screen the counter works from during service.

## Where to find it

Open **Service Hours** in the sidebar. It has three links:

- **Kitchen Queue** — today's open orders, oldest first.
- **Hours & Holidays** — the list of every entry, weekly hours first, then dated overrides.
- **Add Entry** — write a new entry of either kind.

## The two kinds of entry

Every entry starts with one choice, **Entry Type**, and the rest of the form follows it:

| | Weekly hours | Holiday or closure |
|---|---|---|
| **Repeats?** | Every week, on its day | No — one date only |
| **You set** | Day, Opens At, Closes At, Applies To | Date, What Happens, optional hours, Reason |
| **Wins when both apply** | — | Always. A dated entry overrides the weekly hours for that date |

Switching an entry's type clears the other kind's fields when you save, so a row is only
ever one thing.

### Weekly hours

- **Day** — the weekday this span covers.
- **Opens At / Closes At** — 24-hour, in the **shop's own local time**. A span past midnight
  needs two rows, one per day.
- **Applies To** — Delivery and pickup, Delivery only, or Pickup only. Lunch delivery only,
  dine-in all day: that is two rows with different Applies To.
- **Split service** — add two rows on the same day (11:00–14:30 and 18:00–22:00) and the
  storefront shows both spans.

### Holidays and closures

- **Date** — the single day this applies to.
- **What Happens** — *Closed all day*, *Open* (replaces the usual hours), or *Custom hours*
  (short hours). Choosing Closed clears any times on save, so a closed day can never also
  advertise an opening.
- **Reason** — translatable, and shown to customers on the Store Status banner:
  *"Hari Raya — back on Monday."* Worth filling in; a bare "closed" reads as a problem, a
  reason reads as a plan.

## The list

Weekly rows sort in week order first, then the dated overrides by date. The **Entry Type**
filter shows one kind at a time; **Day**, **Mode** and **Status** narrow further.

Each row has three actions: the **eye** opens a read-only view of the entry, the **pencil**
edits it, and the **bin** deletes it after asking.

## Kitchen Queue

Every open order — confirmed or being prepared — oldest first:

- **At a glance**: tiles for New, Preparing, Ready and Out For Delivery, the number of open
  orders, the single longest-waiting order (as `ORD-000320 — 1 h 20 min (Preparing)`), and
  the time the figures were read. Reload the page to refresh them.
- **Orders by state** and **Waiting longest**: a donut of the four counts, and a bar per order
  showing how many minutes it has waited — the top bar is who to serve next.
- **The Queue**: four columns, one per state, oldest first in each. One ticket per order —
  number, mode, waiting time, **when it is wanted** (`ASAP`, or `for 17 Aug 11:00` when the
  customer picked a time at checkout), then every line spelled out:
  `2× Charcoal Chicken [Size: Large · Extras: Cheese, Bacon]`, plus the customer's note.
  Spelling the options out is the whole point of the screen.
- **Advance An Order**: pick the order, pick the state it has reached (Preparing, Ready, Out
  For Delivery, Delivered), then **Move Order**. The order's status changes everywhere at
  once — the queue, Sales, and the customer's order history.

### Moving one order from its own page

You do not have to be on the queue to move an order. Open it under **Sales → Orders**, and
the **Kitchen** tab shows where it is — New, Preparing, Ready, Out for delivery or
Delivered. Pick the state it has reached and **Save Changes**. That does exactly what Move
Order does on the queue: the order's **Order Status** and **Fulfillment Status** in the
sidebar change to match, and the queue shows it in the new column the next time it loads.

Worth knowing:

- **Ready and Out for delivery look the same on the sidebar** — both are Processing /
  Partial. The Kitchen tab is the only place the two differ, which is why it exists.
- **Leaving the tab alone leaves the sidebar alone.** If you only change Order Status or
  Fulfillment Status yourself — putting an order on hold, cancelling it — the Kitchen tab does
  not overwrite what you chose. Only picking a *different* kitchen state moves the order.
- **A cancelled order cannot be moved.** Saving a cancelled order with a new kitchen state
  is refused, and nothing on the order changes.
- **A new online order shows no kitchen state yet.** Orders arrive as Pending; the tab is
  blank until you pick one. Choosing **New** confirms the order and puts it on the queue —
  the way to send a phone order to the kitchen from its own page.
- **The queue's Advance An Order card is still there.** It is the counter's control during
  service; the tab is for when you already have the order open.

## Things worth knowing

- **Hours are shown, not enforced.** The storefront's Store Status banner and Outlet Info
  read these entries, and the menu greys out when you are closed — but nothing yet *refuses*
  an order placed out of hours by someone with a direct link. The form says so on screen.
- **These hours also feed the checkout time picker.** With Restaurant → Scheduled Ordering
  switched on, the cart offers "As soon as possible" or a time slot — and the slots come
  from this list: a holiday removes its day, custom hours replace that day's times. Keep
  the hours honest here and the picker stays honest on its own.
- **Statuses**: an **Inactive** entry is ignored everywhere, which is how you park a seasonal
  schedule without deleting it.
- **Sold-out dishes are not managed here.** That is the **Stock** field on the dish itself —
  Store → Products → the dish → Inventory & Type. `0` means listed but not orderable.
