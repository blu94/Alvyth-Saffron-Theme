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
| **Timezone** | Recorded against the branch. Hours and order times are still judged in the shop's timezone — see [What is recorded but not yet used](#what-is-recorded-but-not-yet-used) |

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

Three fields on this form store what you tell them and nothing reads them yet. They are on the
form because the branch is where the fact belongs, and filling them in now costs nothing:

| Field | Why it does nothing yet |
|---|---|
| **This Outlet Serves Only Selected Dishes** + its dish list | The storefront menu does not vary by branch. A customer browses, then chooses where to collect at the cart — so at the moment the menu is drawn, there is no branch to filter by. Per-branch menus need that ordering decided first |
| **Timezone** | Opening hours, the scheduled-order picker and the checkout guard all read the **shop's** timezone from Settings. A branch in another zone would need Service Hours to become per-branch first |
| **Latitude / Longitude** | A radius delivery zone measures from an origin typed on the **zone**, under Shipping. Nothing reads the branch's own point |

Setting any of them is harmless. None of them is a way to stop a branch selling something
today — for that, use **Status → Inactive**, or the dish's own **Stock** field.

## Deleting a branch

The bin asks first, then removes it from the list. Orders already placed for that branch keep
its name, and restoring the branch restores the dishes you had attached to it.
