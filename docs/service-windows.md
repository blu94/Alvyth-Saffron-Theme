# Service Hours

Service Hours is **when your shop takes orders**: the hours you keep every week, and the
individual dates that differ from them — public holidays, early closes, one-off openings.
Both live in one list, because that is how you think about them: "we open 11 to 10, except
Hari Raya."

It also carries the **Kitchen Queue**, the screen the counter works from during service.

## Where to find it

Open **Service Hours** in the sidebar. It has three links:

- **Kitchen Queue** — today's open orders, oldest first, plus what is booked ahead.
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

Every open order for today — confirmed or being prepared — oldest first. Orders wanted on a
later date are listed separately under **Booked Ahead**:

- **At a glance**: tiles for New, Preparing, Ready and Out For Delivery, the number of open
  orders, how many are booked ahead, the single longest-waiting order (as
  `ORD-000320 — 1 h 20 min (Preparing)`), and the time the figures were read. The screen
  refreshes itself every ten seconds — leave it open on the counter and it stays current.
- **Orders by state** and **Waiting longest**: a donut of the four counts, and a bar per order
  showing how many minutes it has waited — the top bar is who to serve next.
- **The Queue**: four columns, one per state, oldest first in each. One ticket per order —
  number, mode, waiting time, **when it is wanted** (`ASAP`, or `for 17 Aug 11:00` when the
  customer picked a time at checkout), then every line spelled out:
  `2× Charcoal Chicken [Size: Large · Extras: Cheese, Bacon]`, plus the customer's note.
  Spelling the options out is the whole point of the screen.

  A ticket says **DINE IN** and the table (`DINE IN · Table 7`) when the customer chose to eat
  with you, rather than PICKUP — the two look the same to the rest of the system, and this is
  the line that tells you whether to plate it or bag it. It ends **NO CUTLERY** when they said
  they do not need any. Both appear only if you have turned those questions on under
  *Themes → Saffron → Restaurant → Ordering Modes*.
- **Booked Ahead**: orders wanted on a **later date**, grouped by the day they are for, each
  line showing the time and the order number. They are deliberately kept **out** of the four
  columns above until that day arrives — the queue sorts by how long a ticket has waited, so a
  party booked for November would otherwise sit at the top of New for months, ahead of today's
  lunch. The **Booked Ahead** tile counts them, and the **Open Orders** tile does not: twelve
  open orders should mean twelve things to cook now.
- **Advance An Order**: pick the order, pick the state it has reached (New, Preparing, Ready,
  Out For Delivery, Delivered), then **Move Order**. The order's status changes everywhere at
  once — the queue, Sales, and the customer's order history.

### Being told a new order has arrived

A screen that refreshes silently is no use in a busy kitchen: nobody carrying plates is
watching a tablet. So when a new order joins the queue, the page raises a message on screen
and sounds a chime.

**Turning it on and off for this device** — the button at the top right of the Kitchen Queue.
It says one of three things:

| It says | It means |
|---|---|
| **Sound on** | You will hear a chime for each new order |
| **Sound off** | The on-screen message still appears; the room stays quiet |
| **Tap to enable sound** | Your browser will not play a sound until somebody has touched the page. Tap the button — or anything else on the screen — and it starts working. You will hear the chime once, so you know it is working |

That choice is **per device**, not per account: the counter tablet can have sound on while the
same login on the office laptop has it off. It is remembered on that device, including after a
reload.

**Turning it on and off for the whole shop** — **Themes → Ovynt Saffron Theme → Restaurant →
Kitchen Alerts**:

| Setting | What it does |
|---|---|
| **Announce New Orders** | Off, the queue still refreshes but never speaks up |
| **How Insistent** | How many times the chime sounds for one arrival — **Silent**, **once**, **twice** (the default) or **three times**. Silent still shows the message on screen, which suits a counter in a quiet dining room |

Three things it deliberately stays quiet for:

- **Moving an order along.** Sending a ticket to Preparing empties a slot in New, and that is
  not a new order.
- **Orders booked for a later date.** They wait under Booked Ahead and announce themselves on
  the morning they join the queue, which is when there is something to cook.
- **Opening the screen.** However many orders are already waiting, arriving at the page is
  silent — the chime means *something just came in*.

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

### What the customer is told when you move a ticket

Moving an order along tells the customer, on both surfaces at once — a notification in their
account and an email — however you moved it, from the queue or from the order's own page.

| You move it to | They are told |
|---|---|
| **New** | nothing — they have only just ordered and are looking at the confirmation |
| **Preparing** | *"We are cooking order ORD-000123"* |
| **Ready** | *"Order ORD-000123 is ready"* |
| **Out for delivery** | *"Order ORD-000123 is on its way"* |
| **Delivered** | *"Order ORD-000123 is delivered"*, with a link to their receipt |

Worth knowing:

- **The wording is yours to change.** All three sit under **Settings → Notifications & Emails**
  beside your other store emails, and editing one is permanent — reinstalling or updating the
  theme never puts the original wording back.
- **To stop one entirely**, switch it off under **Settings → Notifications**. The customer can
  also turn any of them off for themselves from their account page.
- **A guest order tells nobody.** Someone who ordered without an account has nowhere to receive
  a notification, so only their original order confirmation reaches them. Ask customers to
  create an account if you want them kept posted.
- **Ready and Out for delivery share one email**, because they are the same message with a
  different phrase in it — editing that template changes both.

## Things worth knowing

- **These hours are enforced at checkout.** An order placed while you are closed is refused,
  and so is a scheduled time you are not open for — including by someone with a direct link
  who never saw your cart page. Two rules, and they are judged differently: *as soon as
  possible* is judged on **right now**, a scheduled slot on **the hours of the day it is
  for**. So a customer can order Sunday lunch on a Saturday night, which is the point of
  offering times at all.
- **What the customer is told is what you wrote.** A holiday answers with its own **Reason**;
  an order placed while you are simply closed answers with **Restaurant → Closed Shop
  Message**. Both are worth writing as something a customer can act on ("We reopen Tuesday at
  11") rather than a bare "closed".
- **These hours also feed the checkout time picker.** With Restaurant → Scheduled Ordering
  switched on, the cart offers "As soon as possible" or a time slot — and the slots come
  from this list: a holiday removes its day, custom hours replace that day's times. While you
  are closed the cart drops "As soon as possible" entirely and opens on your next available
  slot. Keep the hours honest here and the picker, the banner and the refusal all stay honest
  together — they read the same entries.
- **A time slightly off the half-hour is still accepted.** The picker offers 11:00 and 11:30;
  a request for 11:07 is inside your hours and is taken. The grid is there to make choosing
  easy, not to narrow what your kitchen will cook.
- **Statuses**: an **Inactive** entry is ignored everywhere, which is how you park a seasonal
  schedule without deleting it.
- **Sold-out dishes are not managed here.** That is the **Stock** field on the dish itself —
  Store → Products → the dish → Inventory & Type. `0` means listed but not orderable.
