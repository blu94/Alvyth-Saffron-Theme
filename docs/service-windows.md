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

## Kitchen Queue

Every open order — confirmed or being prepared — oldest first:

- **Count tiles**: New, Preparing, Ready, Out For Delivery, and the single longest-waiting
  order.
- **The queue**: one block per order — number, mode, state, minutes waiting, then every line
  spelled out: `2× Charcoal Chicken [Size: Large · Extras: Cheese, Bacon]`, plus the
  customer's note. Spelling the options out is the whole point of the screen.
- **Advance An Order**: pick an order and the state it has reached, then save. Refresh by
  reloading the page.

## Things worth knowing

- **Hours are shown, not enforced.** The storefront's Store Status banner and Outlet Info
  read these entries, and the menu greys out when you are closed — but nothing yet *refuses*
  an order placed out of hours by someone with a direct link. The form says so on screen.
- **Statuses**: an **Inactive** entry is ignored everywhere, which is how you park a seasonal
  schedule without deleting it.
- **Sold-out dishes are not managed here.** That is the **Stock** field on the dish itself —
  Store → Products → the dish → Inventory & Type. `0` means listed but not orderable.
