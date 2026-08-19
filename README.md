# Ovynt Saffron Theme

A restaurant and food-ordering theme for [Ovynt](https://github.com/blu94/Ovynt), built to
[`RESTAURANT-THEME-SPEC.md`](../../RESTAURANT-THEME-SPEC.md). Structurally derived from the
Ella theme: same layout shape, settings-schema system, section-driver pattern and Vue-CDN
conventions.

**Ordering modes: delivery *and* pickup, on one menu.** This was the theme's largest known
constraint and it was core's to fix; it landed on 2026-08-17 — see "Delivery and pickup" below.

## Status

Everything the theme can do without a core change is built. What remains is either a core
change or an unanswered product decision — both are itemised in
[`ISSUES-SAFFRON-THEME.md`](../../ISSUES-SAFFRON-THEME.md).

| Phase | Deliverable | State |
|---|---|---|
| 1 | Skeleton from Ella; menu rendering categories and dishes; dish cards | **done** |
| 2 | Dish sheet: variants + free modifier groups; options to the cart | **done** |
| 3 | Modifier admin (groups, answers, dish pivot) | **done** — including bulk attach. Paid add-ons are now a *theme* item: §17.3 is answered, the operator decides per answer and core will not price modifiers |
| 4 | One ordering mode end to end, fee, minimum order | not started |
| 5 | Kitchen queue + dish availability screens | **done** — see the note on the queue below; Kitchen State is also on each order's own edit screen |
| 6–8 | Core §14 items 1–6 | items **2** (checkout fields persist) and **6** (service hours refuse an order, through this theme's `ServiceWindowGuard`) are **done**; 1, 3, 4 and 5 outstanding (core) |
| 9 | Notifications, order tracker, receipts, reorder | not started |
| 10 | RBAC, `.agent/docs/restaurant.md` | docs **done**; `kitchen` resource outstanding |

**Sections:** `Hero`, `MenuSections`, `DishGrid`, `DishMarquee`, `DishSheet`, `StoreStatus`,
`PromoStrip`, `OutletInfo`, `FaqAccordion`, `Newsletter`.
**Core sections this theme claims:** `commerce/ProductDetails` (renders the dish sheet),
`commerce/ProductGrid` and `commerce/CollectionGrid` (render the menu's own card) — see
"Listings and category pages" below.
**Components:** `DishCard`, `CategoryMenu`, `SearchDrawer`, `DynamicForm` (any Forms-module
form, inline), `Breadcrumbs`, `StructuredData` (JSON-LD).
**Saved dishes:** a heart on every dish card and dish sheet, a header counter and the
**Saved dishes** page, behind one *Restaurant → Saved Dishes* switch. See "Saved dishes" below.
**Animation:** every section above except `DishSheet` eases into view on scroll, with its own
effect, speed, stagger and delay under *Styling → Animation*; the theme's **Animation** settings
tab holds the master switch and the defaults a section inherits. See "Animation" below.
**Admin modules:** `outlets` (the branches the shop trades from — a picker appears in the cart's
collection flow only once there is more than one, and the chosen branch rides to checkout as
`data-checkout-field="outlet_id"` and onto the kitchen ticket as `@ Bangsar`),
`modifier-groups` (the questions, plus an **Attach To Dishes** page that puts
one question on many dishes at once), `service-windows` (weekly hours *and* dated holidays in
one list, plus the Kitchen Queue page). Three Outlets fields — the per-branch menu, timezone and
coordinates — are stored and read by nothing yet; `docs/outlets.md` and
`.agent/docs/restaurant.md` both say which and why.
**Extends:** `products` — a Modifiers tab on the dish's own form — and `orders` — a Kitchen
tab that moves an order through New / Preparing / Ready / Out for delivery / Delivered from
its own edit screen, the same write the Kitchen Queue's Advance card makes. Both via core's
module extension seam (`admin/extends/{products,orders}.json` +
`backend/Handlers/{ProductModifierGroups,OrderKitchenState}.php`); the Kitchen tab needed
`OrderController` to call that seam, which core now does. Ready and Out for delivery are one
status pair, so the tab is the only place on the order that tells them apart; an untouched tab
leaves the sidebar's Order Status / Fulfillment Status exactly as saved.
**Tables:** `modifier_groups`, `modifiers`, `dish_modifier_group`, `service_windows`.

Two screens were retired rather than kept. **Dish Availability** toggled `Product.status`,
which unpublishes — so marking a dish sold out took it off the menu instead of greying it
out. Core now enforces `stock` in `validateCart`, so the dish's own Stock field does the job
and `0` means "listed, not orderable". **Service Exceptions** was a second module for dated
closures; it is now `kind = exception` inside `service-windows`, because an operator holds one
concept — opening hours, and the days that differ.

Verified by a 48-assertion functional suite run against the live database inside a rolled-back
transaction, plus JSON, `php -l` and Blade compile-lint passes over every file.

## Layout

```
manifest.json                 name/slug/preview + schema_paths + modules
admin/
├── settings/                 index.json → $ref general / restaurant / header / footer / animation
├── modules/                  own admin screens (modifier-groups, service-windows, …)
├── extends/                  tabs added to a module core owns — {type}.json
└── sections/                 one JSON schema per page-builder section
backend/
├── Handlers/                 writes the keys admin/extends declares
├── Support/                  Motion (animation → data attributes), ThemeSettings (the active
│                             theme's saved settings, for drivers), SectionSetting (section →
│                             theme → built-in resolution for presentation controls)
├── Models/, Repositories/, migrations/
frontend/
├── blade/
│   ├── layout.blade.php      master layout (Vue CDN, theme CSS, header/footer)
│   ├── pages/                one template per core page type
│   └── partials/
│       ├── layout/           header, footer, dynamic-styles
│       ├── components/       {Name}/index.blade.php + index.php
│       └── sections/general/ {Name}/index.blade.php + index.php
└── assets/{css,js}           js/motion.js — scroll reveals + marquee fill (no Vue, no CDN)
```

## Development

Import into the running dev stack — packages, transports, extracts, migrates and activates:

```powershell
.\scripts\import-theme.ps1 -Theme saffron
```

Run from the **repository root**, not from this directory. SCSS is compiled by that script
via `npx sass`; `frontend/assets/css/theme.css` is a build output and must be regenerated
after editing any `.scss` file, because it is what gets packaged.

**What ships.** The package is `manifest.json`, `preview.png`, `README.md` and the four
directories above — nothing else. `.git`, `.claude`, `.playwright-mcp`, `node_modules`,
`.sync-state`, `.gitignore`, `.gitattributes` and the transient `theme.zip` are excluded by
`import-theme.ps1`, in both the full zip and the fast sync. They were **not** excluded before
2026-08-16: `Get-ChildItem -Exclude` filters leaf names, so it never excluded a directory, and
the deployed theme accumulated every browser artefact and local editor setting the repo had
ever collected. If you add a working directory here, add it to that script's two lists.

## Animation

Modelled on the scroll-reveal system fine-dining themes ship (WOW.js + animate.css on the
Armanello demo this was checked against), but self-contained: no CDN library, no build step.

**Where the options are.** Every section carries its own controls under *Styling → Animation*
— **Scroll Animation** (theme default / none / fade up / fade down / fade in / fade from left /
fade from right / zoom in / fade & un-blur), **Animation Speed**, **Stagger Between Items** and
**Animation Delay**. Whatever a section leaves on *Theme default* falls back to the theme's
**Animation** settings tab, which also holds the master switch (**Enable Scroll Animations**),
**Start When** (how far into the viewport an element must be) and **Animate Only Once**.
`DishSheet` deliberately has no animation options — it is the ordering UI, and hiding it until
scrolled would be a bug, not a flourish.

**How it renders.** A section root prints `{!! $motionAttrs !!}` (from
`Theme\Backend\Support\Motion::sectionAttributes($data)`), which becomes
`data-saffron-motion="{effect}"` plus `-delay`, and `-duration` / `-stagger` only when the
section chose its own. Inside, the *layout wrapper* of each thing that should move — a section
head, a grid column, an FAQ item, never the card itself, so the card's own hover transform keeps
working — carries `data-saffron-reveal`; a container with `data-saffron-reveal-group` staggers
its reveals. `js/motion.js` resolves section-first, theme-default second, and reveals with an
IntersectionObserver; stagger is computed **per batch** that enters the viewport together, so a
card that scrolls in alone later shows at once instead of waiting for a queue it was never in.

**Nothing is ever hidden without the code that shows it.** The hidden starting state in
`_motion.scss` exists only under `html.saffron-motion`, which the layout's inline config
script adds when the setting is on **and** the visitor has not asked for reduced motion.
JavaScript off, `prefers-reduced-motion`, print, or the script failing to load all leave the page
exactly as rendered. Verified in a headless browser: reveals with staggered delays, a section's
own effect/speed/stagger/delay overriding the theme defaults, reduced motion → content visible and
the marquee stopped, JavaScript disabled → content visible.

**Dish Marquee** is the one Armanello element added as a section: the featured-dish scroller.
It picks dishes the same way `DishGrid` does (newest / category slug / tag slug), renders a light
card (photo, name, one line, price — no Add button, because a strip that never stops moving is
the wrong place to start an order; every card links to the dish sheet), and glides with a pure
CSS animation whose speed and direction reach the stylesheet through `@push('dynamic_styles')`
on the section's own id, the way Ella's ticker does — so it moves with JavaScript off.
`motion.js` only appends clone pairs when the shop has too few dishes to fill the width. Under
reduced motion the strip stops, the clones disappear and it becomes an ordinary horizontal
scroller.

## Theme settings, and what a section may override

The **Restaurant** tab holds theme-wide presentation defaults — menu layout, dishes per row,
whether dish cards show a price, tags and a description, sticky section navigation, whether
dishes can be saved, and the sold-out and closed-shop wording. A **Menu Sections** or **Dish Grid** block carries the same
controls with a *Theme default* choice, and its driver resolves **section → theme → built-in**
through `Theme\Backend\Support\SectionSetting`. That is the same shape the image ratio and
the Animation controls use, and it is what makes the tab's switches change the menu: before it,
each section schema stored a concrete default and the theme-wide value was read by nothing
(audit A8). Every driver honours its own **Status → Disabled** (A9), and the sold-out wording
reaches the cards and the dish sheet from one setting, which a Dish Sheet block may override
(A7).

Drivers read those settings through `Theme\Backend\Support\ThemeSettings::all()`, which
loads the published `active-{database}.json` through the same cache key ThemeController uses.
Nothing in core shares the settings with a section driver — the `View::shared('settings')`
several drivers used to read was always `null`, so their theme-level fallbacks were dead code
until this helper replaced it. A settings save in admin republishes that file; a value written
straight to the `themes` row does not reach the storefront until it is republished.

**Ordering-mode and review switches were removed from the tab.** Nothing consumed them: the
mode picker is blocked on core (ISSUES O2–O4) and there is no review surface (B3). They come
back with the features rather than sitting live in admin promising behaviour that does not
exist.

**Colours.** `General → Colours` writes each colour as a `--color-{key}` custom property
through `partials/layout/dynamic-styles.blade.php`. The accent's hover shade,
`--color-accent-dark`, is not a setting — it is derived from the accent
(`color-mix(in srgb, var(--color-accent), #000 15%)`) whenever an accent is saved, so a shop
that changes its accent gets matching hovers, prices and focus rings instead of the compiled
saffron (audit A18).

## Search engines

`General → Search Engines → Structured Data` (on by default) prints schema.org JSON-LD built
only from what the theme already knows: a `Restaurant` entity on every page — name, url,
logo, telephone, email, the footer address, social profiles as `sameAs`, and
`openingHoursSpecification` from the recurring whole-shop service windows (the same rows
Outlet Info prints); a `Product` with an `Offer` / `AggregateOffer` on a dish page whose
availability follows the stock rule core enforces; a `BreadcrumbList` beside the breadcrumbs;
and a `FAQPage` beside an FAQ Accordion. The `StructuredData` component prints the first two
from the layout head; the other two ship next to the markup they describe. Turn it off for a
shop whose SEO plugin already publishes these entities. The SEO tab's own JSON-LD on a page
is printed separately and left alone.

`General → User Interface → Show Breadcrumbs` (on by default) draws a *Home › Categories ›
Burgers › Dish* trail above dishes, categories, posts and pages, derived from the record the
page resolved to. The listing crumbs are the shop's own CATEGORIES and BLOGS pages, titled
however the operator titled them.

## Header extras

`Header → Show Language Switcher` shows a language menu in the icon cluster whenever more
than one locale is active in Settings → Localization. It is Ella's `header_show_locale_switcher`
with Ella's URL rule (strip the current locale segment, prefix the chosen one unless it is the
default, keep the query string) drawn as a Saffron dropdown — the same hover / focus / caret
machinery as a nav dropdown, end-aligned so it stays on-screen on a phone.

`Header → Search Placeholder` (translatable, shown while Show Search is on) is the grey prompt
inside the search panel's box; empty means "Search the menu". The panel always offers a way
out: **Browse the whole menu** — a link under the section shortcuts before anything is typed,
and a button when a search finds nothing — pointing at the shop's own Categories page, the
same record the breadcrumb trail resolves, so a renamed or translated slug still works. A
shop with no Categories page gets no link rather than a link to a 404.

## Blog

The blog index paginates with core's shared pager (the plain Previous / Page N of M / Next
that `collection_grid`, `product_grid` and `blog_grid` print), dressed by the theme — never
Laravel's default paginator view, whose Tailwind chevrons render at page width in a theme that
ships no Tailwind. A single post shows its featured image, date and author, the lead paragraph,
any legacy content blocks (`html` / `text` / `image` in the post's `data`, as Ella renders
them, so a post migrated from an Ella shop keeps its body), and Previous / Next story links.
There is deliberately no sidebar and no comments — a restaurant's stories do not need them.

## Print

`@media print` hides the announcement bar, header, footer, search panel, go-to-top button and
every button, so a printed order confirmation or order detail is the content alone. It is one
rule set in the layout rather than a per-page block, because the chrome is the same everywhere.

## Delivery and pickup

Both, on the same menu, chosen by the customer on the cart — **Restaurant → Ordering Modes**
decides whether they are offered the choice or the shop simply does one of them.

This was the theme's largest known constraint and it was core's to fix. Whether checkout
demanded an address was decided by `Product.requires_shipping`, a column on the shared
catalogue, so a shop had to pick one mode for everything it sold: flag a dish shippable and
every collection order was refused for want of an address; flag it not-shippable and no
delivery fee could ever be charged. There was no third setting (spec §3, §14 item 3, register
O4).

**Core now records a fulfilment type on the order** and that decides, with the product flag
still deciding whether there is anything to ship at all — a digital line needs no address on
either mode. It is `Order::FULFILLMENT_TYPE_DELIVERY` / `_PICKUP`, a real column, because a
fact that gates a fee must survive every save; `meta` does not (see the Kitchen tab's warning
about `OrderRepository::update()`). Click-and-collect is ordinary commerce, so it lives in core
rather than here — a bookshop needs the same distinction.

**The control is this theme's, the contract is core's.** Core's Cart section reads
`[data-checkout-mode]` from anywhere on the page and posts it as `fulfillment_type` — the same
shape as the `[data-checkout-field]` bag the scheduled time already rides in. How a restaurant
presents "deliver it or come and get it" is a presentation decision; a bookshop wants a quieter
one.

`components/OrderMode` renders two tiles and seats itself inside the order summary **above**
the schedule block and Proceed to Checkout, so the summary reads: what kind of order, then
when, then place it. Choosing pickup hides the delivery address form, charges no fee, and shows
the collection address and ready-by wording; choosing delivery brings the form back. A shop
offering only one mode renders no picker at all — just a hidden input carrying its one mode, so
the server still learns which it is and the customer is not asked a question with one answer.
The collection address falls back to the footer address, because a shop that filled one in
should not have to type it twice.

## Paid add-ons

An answer may carry a **Price Change**, and it is charged — added to the dish's own price, per
portion, so two burgers with extra cheese pay for two cheeses. It shows as **one line** on the
cart, the order and the receipt with the chosen options listed underneath, which is the shape
every food-delivery app and every restaurant point-of-sale uses. There is no separate line item
and no phantom product for an add-on.

**Core does the arithmetic; this theme supplies the meaning.** `validateCart` prices a line
from the Product and its variant and cannot read a modifier — `modifier_groups` is a table this
theme's own migration creates, and core must not learn its name. So core declares a seam and
the theme answers it, the same declarative shape as the checkout guard:

```json
"cart": { "line_pricer": "Theme\\Backend\\Pricing\\ModifierPricing" }
```

`backend/Pricing/ModifierPricing.php` resolves a line's answers against the questions its dish
declares, and that one lookup does **both** jobs — it returns the surcharge *and* refuses a line
whose answers are not ones the dish offers. They were two entries on the register (O1 and O7)
and are deliberately one piece of code: pricing an answer requires finding it, and finding it is
the validation.

Four refusals, each a sentence the customer reads: a compulsory question left unanswered, an
answer the dish does not offer, more answers than the question allows, and fewer than it
requires. The dish sheet already enforced all of these — in the browser only, so anything
posting directly to checkout met none of them.

It hooks `validateCart`, which is the single point every path is priced through — the cart page,
`POST /storefront/checkout` and the checkout preview all call it — so the surcharge reaches
`OrderItem.unit_price` and the receipt agrees with the dish sheet by construction rather than by
care. A refusal lands in `cart_errors`, which checkout already answers 422 on, so no order is
written.

**It fails open**, like the checkout guard: a pricer that cannot be constructed or that throws
is logged and skipped, and the line keeps its base price. Undercharging one line is recoverable;
a shop whose every line is refused because a theme table is missing is not.

## Sharing a dish

A share control beside the save heart on the dish sheet. Clicking it opens a panel of network
buttons — five to a row, brand-coloured on hover, Copy link last — which is the same control
Ella puts on a product page, offering the same 28 networks and reading the **same switches**:
`share_facebook`, `share_whatsapp` and the rest on **Settings → Application**. A network turned
off there disappears from both themes at once, and one that has never been set defaults to on.
Because this theme ships no icon font, the glyphs are Tabler bodies inlined by the driver rather
than Ella's `<i class="ti ti-brand-…">`.

**It used to reach for `navigator.share` first**, on the argument that a phone's own sheet is
what actually reaches WhatsApp. That was wrong for a themed shop: desktop Chrome on Windows
*has* `navigator.share`, so the control that was supposed to be the fallback was the only thing
most customers never saw — they got a Windows dialog listing Mail and Bluetooth instead of the
networks the operator had chosen, and the two themes behaved differently on the same site. The
panel is now the whole control, on every device.

Three things here are deliberately *not* what they look like, and all three were measured rather
than assumed:

- **The panel does not use `data-share-popup`.** The shipped `storefront.min.js` does carry a
  handler for that attribute, and it would have been the obvious reuse — but it binds once at
  page load, and this panel is created by Vue when the button is clicked, so it would never
  have been bound. The links would have looked correct and navigated the customer off the shop
  instead of opening a share window. Verified by dispatching a click and watching nothing
  intercept it. The component opens its own centred popup.
- **Copy is handled here rather than by `data-share-copy`.** That handler reports success by
  toggling a class named `ella-share-tooltip`; a translated label on this theme's own button
  says the same thing without borrowing another theme's namespace.
- **`mailto:`, `sms:` and `viber:` are not opened in a popup window.** The bundle's handler
  calls `window.open` for every href it intercepts, which for those three leaves an empty
  window sitting on the screen while the mail client loads behind it. `openShare` popups
  `http(s)` only and lets the rest navigate to the device's own app — the one behaviour of
  Ella's that was not worth copying.

The panel closes on an outside click, on Escape with focus returned to the trigger, and after
a network is picked. Copy leaves it open and shows a "Link copied" toast, as Ella's does.

## Quick view

A magnifier under the save heart on every dish card opens a closer look without leaving the
menu: every photograph with a thumbnail strip, the full description, the price and the labels.
One switch, *Restaurant → Quick View*.

**It is a look, not a second ordering form**, and that is the design rather than a shortcut. A
quick view carrying sizes and compulsory questions would be the dish sheet written twice, and the
two copies would drift on the one screen where drift costs money. So the ordering decision stays
exactly where `$canQuickAdd` already puts it: added straight from the dialog when the dish asks
nothing at all, and handed to the sheet the moment it has a size or a compulsory question. A
sold-out dish shows its wording and offers only *View dish*. That is the same line the saved-dishes
list draws, for the same reason (audit A1).

**The dialog is teleported to `<body>`, and that is load-bearing.** The card is `overflow: hidden`
and takes `transform: translateY(-2px)` on hover, and a transformed ancestor becomes the containing
block for `position: fixed` — so a dialog left inside the card would have been measured against the
card and clipped by it, precisely while the pointer rested on the card, which is the only way it
opens. Measured rather than assumed: a `position: fixed; inset: 0` probe placed inside a card comes
back **380×441**, the card's own size, against a 1440×900 viewport. `<Teleport>` is in the Vue 3
CDN global build, so this costs no build step.

The trigger is **always visible, not revealed on hover** — a hover-only control on this theme
shipped unreachable once already, and a phone has no hover at all. Escape and the close button both
return focus to the trigger that opened the dialog, and the body scroll is locked while it is open,
the same way the dish sheet's lightbox behaves.

## Categories and tags on a dish

Under the description, the dish sheet prints the courses the dish belongs to and the labels it
carries — `Categories:` then `Tags:`, each a wrapping row of chips, exactly where Ella's product
page prints them. Two switches on the **Dish Sheet** block, *Show Categories* and *Show Tags*,
both on by default. The dish card has always shown its tags; the page a customer decides on
showed neither (register E4).

**Categories link and tags do not**, and the asymmetry is measured rather than an oversight.
`Category::store_url` resolves to `/collections/{slug}`, which `ThemeController` matches to a real
category page — verified by following one to a listing of nine dishes. A tag has no page: core
answers `/collections?tag={slug}` by sharing a `currentTag`, and **nothing anywhere reads it** —
not core's collection or product grids, not this theme's overrides of them — so the URL renders
the whole unfiltered menu. A chip that offers "just the spicy dishes" and returns all of them is
worse than a chip that stays put, so tags render as plain labels, the way `DishCard` renders them.
Making that filter real is a listings change, not a dish-sheet one.

The chips keep the full `.saffron-badge` scale rather than the dish card's reduced one — the sheet
has a whole column to sit in — and the linked ones take a 40px minimum on coarse pointers, because
a 25px uppercase pill is not a thumb target.

## Saved dishes

A heart on every dish card and on the dish sheet, a counter in the header, and a **Saved
dishes** page listing what a customer meant to come back for. One switch turns the whole
feature on or off — **Restaurant → Saved Dishes** — because three switches for one feature is
three places to leave it half-on.

**The list lives in the customer's browser**, in `localStorage.ovynt_wishlist`, through the
same `window.OvyntStore` the cart uses. It does not follow them to another device and the
server never sees it. Turning the switch off hides every control and the page explains itself;
it does **not** delete anything, so the entries come back when the switch does.

**The primary action on a saved dish is *View dish*, not *Add to cart*** — the one deliberate
divergence from Ella's wishlist, which adds straight to the cart. A dish here may ask a
compulsory question ("Choose your protein"), and adding it from a list would send the kitchen
an order with no answers. That is the same defect the dish card's quick-add gate exists to
prevent (audit A1), and a saved-dishes list is exactly where it would come back. The sheet is
where those questions get answered.

Each saved line carries its own `url`, which is why the theme writes the entry itself instead
of calling `OvyntStore.toggleWishlist()` — that method copies id, title, price and image by
name and drops everything else, and a list rendered from `localStorage` alone has no other way
back to the dish. The write still goes through the store's own `saveWishlist()`, so nothing
bypasses its persistence. Both lists are also mirrored across tabs by the header's `storage`
listener, so saving in one tab updates the counter in another.

> **Guests hit a login wall on the page, and that is core's rule, not this theme's.**
> `Page::ACCOUNT_PAGE_TYPES` lists `WISHLIST` beside `PROFILE`, so `/wishlist` redirects a
> signed-out visitor to `/login?redirect=/wishlist`. The hearts themselves work fine for a
> guest — the list is in their browser — so a guest can save dishes and then be asked to sign
> in to look at them. Ella has the same behaviour. Removing `WISHLIST` from that constant is a
> one-line core change and a decision for the shop, not something a theme can override.

## Scheduled ordering

The cart page carries a **When do you want it** block: *As soon as possible*, or a day and a
30-minute slot picked from the shop's own Service Hours — a dated closure removes its day, a
dated override replaces that day's hours, and today only offers times at least half an hour
out. Controlled from **Restaurant → Scheduled Ordering** (the switch, and how many days ahead;
both consumed by the block's driver). The block renders nothing when scheduling is off, when
no whole-shop hours are authored, or while the cart is empty.

**While the shop is closed, *As soon as possible* is not offered at all.** There is nothing to
be as soon as possible about — the kitchen is shut — so the block says so and opens on the
first slot it can actually honour. Offering ASAP there was a button whose only outcome was a
refusal.

**Where it sits, and why it moves.** The block seats itself inside the order summary, directly
above **Proceed to Checkout** — a control that changes the order has to be met before the button
that places it. It cannot be *rendered* there: the summary card is core's Cart section, and a
theme may only precede or follow that whole section (the same limit that puts the checkout plugin
slot where it is, audit A15). So the server renders it **above** the cart — already ahead of the
button — and the component moves itself next to `[data-co-place]` once that button is in the DOM.
If core ever drops that hook the block simply stays where the server put it, which is still
before the button. It first shipped *below* the cart, roughly 200px past the checkout button,
where a customer who did not scroll would never have known scheduling existed.

The chosen value is an ordinary `[data-checkout-field]` named `scheduled_at`, so core's Cart
section carries it to checkout with the plugin fields, and core persists the validated bag to
`order.meta.checkout_fields` (spec §14 item 2 — the core half of this feature). The Kitchen
Queue reads it back: every ticket now ends `ASAP` or `for 17 Aug 11:00`.

**Shown *and* enforced.** `backend/Guards/ServiceWindowGuard.php` refuses at checkout what the
picker declines to offer: an ASAP order while the shop is shut, and a slot outside the *target*
day's hours, already past, or further ahead than the picker goes. It is declared in
`manifest.json` under `checkout.guards` and core constructs it before the order row exists —
core's checkout-guard seam (spec §14 item 6, register O5), which exists because a theme cannot
register an event listener and core cannot query a table this theme created.

The picker and the guard read one implementation — `ServiceWindowRepository::hoursForDate()`,
which the Store Status banner reads too — and clamp the booking horizon with one method,
`schedulingHorizon()`. That is not tidiness: two copies of "how many days ahead" is how a shop
comes to offer a slot its own server then refuses.

## Listings and category pages

Three screens used to fall through to core's generic commerce renderers, which meant Bootstrap
primary-blue buttons on a saffron-and-cream site, prices printed as bare numbers with no `RM`,
and a grey *No image* box instead of the theme's dish placeholder — all inside Saffron's own
header and footer. `/products` was the worst of them and is reachable from search and from any
link a customer is sent.

**A theme owns every screen a customer can reach, or it does not ship the route.** So this theme
now claims core's two listing sections the same way it already claimed the dish page:
`partials/sections/commerce/{ProductGrid,CollectionGrid}`. `section.blade.php` checks the theme
class before core's, so every page that already places either block re-skins with no data edit
and no re-authoring, and switching the theme off hands them back.

**Core's filtering, sorting and pagination are kept exactly as they were** — they work, and they
are plain GET links, so a narrowed listing is a shareable URL and the back button behaves. Only
the appearance changed: the results render through `DishCard`, the same component the menu uses,
so a listing and the menu cannot disagree about whether a dish shows its price or what the
sold-out badge says. The drivers mirror core's query rather than inventing one, with a single
addition — `DishCard` reads variants and tags on every card and core loads neither, so rendering
core's own paginator would have cost two queries per dish.

Below `lg` the Product Grid's three filter groups collapse into a `<details>` disclosure. They
stack to about a phone screen and a half, so a customer landing on `/products` met filters and
no food; the panel is one 44px control now, and no JavaScript is involved. From `lg` up the
stylesheet hides the summary and forces the panel open — otherwise a customer who closed it on
their phone would rotate to landscape and find it still closed with no control left to reopen it.

**Category pages have a default.** `/collections/{slug}` renders the Category's builder rows, and
a freshly installed shop has none on any category — so every category page printed *"This part of
the menu is being updated"* until an operator opened each one by hand. The breadcrumb on every
dish page links there, which made the theme's most-reached secondary screen a dead end on a shop
that had done nothing wrong. The `CategoryMenu` component now renders the block an operator would
have added — the Menu Sections section, scoped to that category's own slug — so the automatic and
hand-built paths draw through the same code and cannot drift. Rows still win wherever they exist,
so a custom layout overrides the default rather than competing with it, and the empty-state
message is kept for the case it was written for: a category that genuinely has no dishes.

One defect surfaced on the way and is worth knowing, because it was silent: Menu Sections prefers
categories typed `product` when any exist, and applied that **before** its own `only_slugs`
allow-list. So a block scoped to a category carrying the legacy `general` type rendered nothing
at all on any shop that also had one properly typed category. An explicit allow-list is not a
guess, so naming slugs now turns the preference off.

## The kitchen hears an order arrive

The Kitchen Queue refreshes itself every ten seconds **and announces an arrival** — a message on
screen and a chime. Both halves are declarative: `kitchenData()` returns `poll_seconds` and an
`alert` block, and core does the work, because a theme may not ship admin Vue and a `display`
field cannot carry a script. The seam is core's, general to any module's custom page;
`.agent/docs/module-extensions.md` has the contract.

**Which number is watched is the whole design.** Not the New count — a counter who moves one
ticket to Preparing in the same ten seconds another order arrives leaves that count exactly where
it was, and the arrival would pass in silence at the one moment the kitchen most needs telling.
It watches the **highest order id in the live columns**, which only an arrival can raise.
Orders booked for a later date are excluded, for the same reason the Open Orders tile excludes
them: the bell means "cook this now", and a party in October is not that. It rings on the morning
that booking joins the queue.

**Restaurant → Kitchen Alerts** is the shop's control — whether to announce at all, and how
insistent the chime is (silent, once, twice, three times; silent still shows the message). Sound
on or off is a *per-device* button on the page itself, because a counter tablet wants it on and
the owner's laptop does not, and they are the same account.

The tone is three synthesised notes, not an audio file — a theme cannot write into core's
`public/`, the same limit that stops it shipping guide screenshots. And the button says which of
three states it is in rather than claiming sound is on while the browser refuses it: audio cannot
start until somebody has touched the page, so a freshly opened tablet reads *Tap to enable sound*
and plays the tone back once when it is.

## Attaching one question to many dishes

A dish decides which questions it asks, on its own **Modifiers** tab — that is where `orders`,
the position of a question on the dish sheet, is authored. But putting "Choose your side" on
thirty burgers was thirty product forms, so **Modifier Groups → Attach To Dishes** does it in
one action: pick the question, pick the dishes, Apply. Detach is the same screen with the Action
switched.

It stays a *second* screen rather than a second **writer**. A new attachment lands one past
whatever that dish's last question sits at — exactly where the tab would have drawn a new last
row — and a dish that already asks the question is left completely alone, so a per-dish
*Required* somebody set on that dish's form survives a bulk apply aimed at forty others. The
earlier `dish_ids` picker on the group screen was removed precisely because it did not have
those two properties: it wrote `orders` meaning "the dish's position in the group" while the tab
wrote it meaning "the question's position on the dish", and each silently reordered the other's.

The group's own form carries the read-only other half — **Dishes That Ask This Question**, the
reach as a list of names — and the Attach screen shows the whole menu's: how many dishes ask
each question, and which dishes ask nothing at all.

## Newsletter

The **Newsletter Signup** section is a heading, a subheading and a line of small print around a
form from the **Forms** module — pick the form in the block, and an Email field plus a Submit
button is the whole setup. It reuses the `DynamicForm` component in its `inline` variant, so a
signup lands beside the shop's other leads and the success message is the form's own. Ella's
section posts to a free-text endpoint that core does not have; this one deliberately does not.
Three tones (sand / dark / accent) and two layouts (centered / split).

## Conventions this theme is bound by

- **Zero build steps** on the storefront. Vue 3 CDN global build, no SFCs, no bundler. The
  one compile step is SCSS, run on the dev machine.
- **No data logic in Blade.** Every section and component is a co-located pair —
  `{Name}/index.blade.php` for presentation, `{Name}/index.php` for the driver class
  implementing `render(array $data, string $locale, string $themeViewPath): string`.
- **Multi-word section schemas must declare an explicit snake_case `type`.** The builder
  derives the driver class name from that type
  (`MENU_SECTIONS_SECTION` → `MenuSections`); with the type left to be inferred from the
  filename it would derive `Menusections` and the driver would not be found on a
  case-sensitive filesystem.
- **Theme sections resolve to the `general` group.** `SectionRegistry::getSectionMap()`
  indexes only core section schemas, so any type without a core counterpart falls back to
  `general` — which is why the drivers live under `partials/sections/general/` even for
  commerce-shaped blocks.
- **Plugin slots are mandatory.** `account`, `checkout` and `not-found` are rendered by
  this theme; a theme that omits one silently drops storefront features the shop paid for.

## Known constraints

Verified against core, not assumed. Each is a limit on what this theme can promise.

- **A delivery fee is still priced per zone, not per distance.** Shipping zones match on
  country and state only, so every address in a state pays the same fee and an order forty
  kilometres out is accepted on the same terms as one two kilometres out. Fine with a
  state-wide courier, wrong with your own riders. Decided 2026-08-17: the shape is the
  **operator's** choice per zone — state, a postcode list, or a distance radius from the outlet,
  mixable within one shop — rather than this project picking one delivery model on their behalf.
  Not built (register O7b).
- **Dine in and the cutlery prompt are built** (O2, 2026-08-19), both off by default. Turning
  *Offer Dine In* on adds a third tile beside Delivery and Pickup and asks which table — a list
  of 1..N when the shop says how many tables it has, free text otherwise. **Core is still told
  `pickup`**: a diner needs no address and pays no delivery fee, and `fulfillment_type` knows
  only the two. Which kind of collection it is rides on `checkout_fields` as `dining` +
  `table_number`, so the kitchen ticket reads `DINE IN · Table 7` where core reads `pickup`.
  *Ask About Cutlery* adds an opt-out for delivery and collection (never for a diner at a laid
  table) and prints `NO CUTLERY` on the ticket.
- **Stock is a real count** (O6, 2026-08-19). Placing an order reduces it inside the order's own
  transaction, in one conditional statement, so the last portion cannot be sold twice; cancelling
  gives it back. Empty still means unlimited.
- **Service hours do not yet distinguish delivery from pickup.** A window's **Applies To**
  (`delivery` / `pickup`) is stored and displayed but still not consulted by the refusal. It
  was blocked on there being no ordering mode to compare against; now that an order carries
  one, `ServiceWindowGuard` can read it — the guard is where "lunch is delivery only" becomes
  real, and it is the obvious next step rather than a constraint.
- **There is no `MENU` page type**, and a theme cannot add one — page types live in core's
  `storage/app/defaults/schema/pages/`. So there is deliberately **no `pages/menu.blade.php`**;
  `ThemeController` would never resolve it. The menu is a `MENU_SECTIONS_SECTION` block
  placed on an ordinary page through the page builder, which is also how the spec's §9.2
  describes it.
- **`vite.config.storefront.ts` hard-codes its output into `theme/ella/`.** The
  `storefront.min.js` / `storefront.min.css` vendor bundle in this theme is a copy of that
  build output. Rebuilding it for Saffron needs a theme argument on that config — a core
  change, not a theme one.
- **An option key naming no question the dish asks is carried, not refused.** A line's answers
  are now fully validated against that dish's own questions, but `Size` is injected by core
  itself for a dish with variants and `Notes` is free text — from the pricer's point of view
  both name no question, so unknown keys are ignored rather than rejected. The residue is
  cosmetic: a hand-crafted request can put a stray key on a kitchen ticket. It carries no price
  and satisfies no requirement, so it cannot obtain anything.
- **The kitchen queue is tiles, charts and text columns, not a board of draggable cards.**
  The admin schema engine has no board or card-list field type and a theme may not ship admin
  Vue, so the queue renders from the field types that exist — the same stat tiles and charts the
  Loyalty overview uses, plus one text column per kitchen state. The repository already returns
  the data shaped as columns, so a board renderer can be dropped in without touching it.
- **The preview is `preview.png`, not `preview.webp`.** No WebP encoder exists on the container
  or the host — GD is built without it, and there is no `imagick`, `cwebp`, `magick` or
  `ffmpeg`. `create-theme.md` step 6 permits either format. WebP would need the browser-canvas
  `toDataURL('image/webp')` route.

## Licence

Proprietary. © Ovynt.
