# Outlets

Outlets is **the places your shop trades from** — the branch in Bangsar, the one in KLCC, the
kitchen that only delivers. One catalogue, one set of prices, several addresses.

A shop with no outlets, or with one, behaves exactly as it did before this screen existed:
customers see a single collection address and are never asked to choose. Add a second and the
question appears by itself.

> **This is not multi-site.** Every branch shares one menu, one set of orders and one admin.
> If you need separate shops with separate catalogues, that is a different feature.

## Where to find it

Open **Saffron → Outlets** in the sidebar — every screen this theme adds lives under the
one **Saffron** group. It has two links:

- **List** — every branch you have created.
- **Add Outlet** — write a new one.

## The form

### The outlet

| Field | What it is for |
|---|---|
| **Outlet Name** | What the customer sees when they choose where to collect from. Use the name locals use — the district or the mall, not your internal branch code. Translatable: switch locale in the sidebar to add other languages |
| **Collection Address** | Shown to a customer collecting from here. Leave it empty and the footer address is used instead |
| **Phone** | Shown with the address. Include the country code so a customer can tap to call |
| **Timezone** | Used when this branch keeps opening hours of its own — Service Hours entries naming this branch are judged on this clock. A branch without its own hours, or with this left empty, follows the shop's timezone from Settings |

### What this outlet serves

Off by default, and off is what almost every branch wants: it serves the whole menu. The
switch and its dish picker are recorded but **do not change the online menu yet** — see below.

### How it takes orders

| Switch | Meaning |
|---|---|
| **Offers Collection** | Customers may choose to collect from here. A branch with this off never appears in the picker |
| **Offers Delivery** | This branch delivers |
| **Default Outlet** | The branch used for a customer who has not chosen one. Setting it here clears it from whichever branch held it before — there is always exactly one |

A branch with both switches off takes no orders at all. That is allowed, and it is how you
park a site you are not ready to open; **Status → Inactive** is the cleaner way to say the same
thing.

> You cannot end up with no default. Create the first branch and it becomes the default whether
> or not you ticked it, because the storefront needs somewhere to fall back to.

### The dining room

Whether people eat in **at this branch**, and which tables they sit at.

| Field | What it is for |
|---|---|
| **Offers Dine In** | Off by default. On, a customer who picks this branch is offered *Dine in* beside Collection and Delivery, and is asked which table |
| **Tables** | One row per table, listed the way you would walk the room. **Table** is what prints on the kitchen ticket, so write it exactly as your staff say it out loud. **Seats** decides which tables a party is offered; leave it empty and the table fits anybody. **Out of service** parks a table without deleting it |

> **Dine In starts OFF at every branch, including the ones you already have.** It used to be a
> single shop-wide switch (*Themes → Saffron → Restaurant → Offer Dine In*) that rode on
> collection, so a takeaway kiosk with a counter and no seating was offered *Dine in* too. It is
> per branch now. **If your shop was already offering dine-in, tick the branches that seat
> people** — until you do, no branch offers it. Nothing was deleted: the shop-wide switch is
> untouched and every table you had listed is still there.

Two things have to be true before a customer is offered *Dine in* at all:

1. **Offer Dine In** is on for the shop, under *Themes → Saffron → Restaurant*. That switch now
   permits dining; each branch decides whether it actually seats anybody.
2. This branch has **Offers Collection** on. A diner is recorded as collecting — they need no
   address and pay no delivery fee — so a branch that has stopped collecting cannot seat a
   diner however this switch is set.

If nobody at the shop dines in, the *Dine in* tile is not drawn at all rather than drawn and
then withdrawn when a branch is picked. A control that appears and vanishes reads as a fault.

A customer who chooses a branch with no dining room is told so and offered the way out, and an
order that reaches the till as a diner at such a branch is refused outright — the same shape
the menu restriction uses: the hiding is what the customer sees, the refusal is what holds.

**Leave Tables empty** and the branch falls back to the shop-wide *How Many Tables* count under
*Themes → Saffron → Restaurant*, which gives every branch the same room. That is the upgrade
path, not a setting to keep: it is fine for one restaurant and wrong the moment two of them
differ, because a diner would be offered Table 15 in a room with eight tables. With neither, the
customer types whatever their table is called.

### Where it is

Latitude and longitude for the branch. Right-click the shop in Google Maps and copy the two
numbers. Recorded on the branch — a radius delivery zone carries its own centre point, typed on
the zone itself, so filling these in changes no delivery charge today.

### The sidebar

| Field | Notes |
|---|---|
| **Slug** | Used in links. Leave it empty and it is made from the name; type one and it is tidied into a slug. Kept unique automatically — a second *Taman Tun* becomes `taman-tun-2` |
| **Status** | Inactive hides the branch from customers immediately and keeps it, and its orders, intact |
| **Display Order** | Lower sorts first, in this list and in the customer's branch picker |

## The list

ID, Outlet, Slug, Phone, Collection, Delivery, Default and Status. The **Status** filter
narrows it; the search box matches name, slug, address and phone.

Each row has an **eye** that opens a read-only view and a blue **pencil** that edits. The
**bin** appears only for an administrator whose role carries the delete permission.

## What the customer sees

The branch question lives **inside the collection flow on the cart**, not on the menu — nobody
should have to pick a branch before they can look at the food.

- **Two or more collection branches** → a *Which branch?* picker, opening on your default, with
  that branch's address and phone underneath.
- **Exactly one** → no picker at all. Its address simply becomes the collection address.
- **None created** → unchanged from a single-address shop.

Whichever branch is chosen travels with the order, so a one-branch shop still records where the
order belongs.

## What the kitchen sees

A ticket for a branch carries `@ Bangsar` in its header on the **Kitchen Queue**. A shop that
runs one site sees no such line — it already knows.

## What is recorded but not yet used

One field on this form stores what you tell it and nothing reads it yet. It is on the form
because the branch is where the fact belongs, and filling it in now costs nothing:

| Field | Why it does nothing yet |
|---|---|
| **Latitude / Longitude** | A radius delivery zone measures from an origin typed on the **zone**, under Shipping. Nothing reads the branch's own point yet — per-branch delivery reach is planned, per branch, because some branches run their own riders and some hand off to a courier |

Setting it is harmless. It is not a way to stop a branch selling something today — for that,
use **Status → Inactive**, or the dish's own **Stock** field.

Two rows used to sit beside it, and both became features: the per-branch dish list became the
dish's own **Availability** tab (this screen now shows a read-only mirror of what is exclusive
here, and the dish's form is the only place that changes it), and **Timezone** is read whenever
this branch keeps its own Service Hours (above).

## Deleting a branch

The bin asks first, then removes it from the list. Orders already placed for that branch keep
its name, and restoring the branch restores the dishes you had attached to it.
