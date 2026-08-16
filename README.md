# Ovynt Saffron Theme

A restaurant and food-ordering theme for [Ovynt](https://github.com/blu94/Ovynt), built to
[`RESTAURANT-THEME-SPEC.md`](../../RESTAURANT-THEME-SPEC.md). Structurally derived from the
Ella theme: same layout shape, settings-schema system, section-driver pattern and Vue-CDN
conventions.

**Target ordering modes: delivery *and* pickup.** See "Known constraints" below — that
combination is blocked on core and cannot be worked around in the theme.

## Status

Everything the theme can do without a core change is built. What remains is either a core
change or an unanswered product decision — both are itemised in
[`ISSUES-SAFFRON-THEME.md`](../../ISSUES-SAFFRON-THEME.md).

| Phase | Deliverable | State |
|---|---|---|
| 1 | Skeleton from Ella; menu rendering categories and dishes; dish cards | **done** |
| 2 | Dish sheet: variants + free modifier groups; options to the cart | **done** |
| 3 | Modifier admin (groups, answers, dish pivot) | **done** — paid add-ons blocked on §17.3 |
| 4 | One ordering mode end to end, fee, minimum order | not started |
| 5 | Kitchen queue + dish availability screens | **done** — see the note on the queue below; Kitchen State is also on each order's own edit screen |
| 6–8 | Core §14 items 1–6 | not started (core) |
| 9 | Notifications, order tracker, receipts, reorder | not started |
| 10 | RBAC, `.agent/docs/restaurant.md` | docs **done**; `kitchen` resource outstanding |

**Sections:** `Hero`, `MenuSections`, `DishGrid`, `DishMarquee`, `DishSheet`, `StoreStatus`,
`PromoStrip`, `OutletInfo`, `FaqAccordion`, `Newsletter`.
**Components:** `DishCard`, `DishSheet`-backed `ProductDetails` override, `SearchDrawer`,
`DynamicForm` (any Forms-module form, inline), `Breadcrumbs`, `StructuredData` (JSON-LD).
**Animation:** every section above except `DishSheet` eases into view on scroll, with its own
effect, speed, stagger and delay under *Styling → Animation*; the theme's **Animation** settings
tab holds the master switch and the defaults a section inherits. See "Animation" below.
**Admin modules:** `modifier-groups`, `service-windows` (weekly hours *and* dated holidays in
one list, plus the Kitchen Queue page).
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
whether dish cards show a price, tags and a description, sticky section navigation, and the
sold-out and closed-shop wording. A **Menu Sections** or **Dish Grid** block carries the same
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

- **Delivery and pickup cannot both be offered on the same menu yet.** Whether checkout
  demands an address is decided by `cartRequiresShipping()`, which reads the shared
  `Product.requires_shipping` column and has no notion of an ordering mode. Spec §14
  items 2 and 3 (phase 6) are what unlock it. Until then this theme can ship
  **delivery-only** or **pickup-only** correctly. There is deliberately no mode picker and no
  ordering-mode settings yet — both arrive with the core change.
- **Nothing decrements or validates stock.** A sold-out dish keeps selling. Spec §14 item 1.
- **`plugin_fields` are read but not persisted** — a scheduled time, table number or
  "no cutlery" is lost after pricing. Spec §14 item 2.
- **There is no `MENU` page type**, and a theme cannot add one — page types live in core's
  `storage/app/defaults/schema/pages/`. So there is deliberately **no `pages/menu.blade.php`**;
  `ThemeController` would never resolve it. The menu is a `MENU_SECTIONS_SECTION` block
  placed on an ordinary page through the page builder, which is also how the spec's §9.2
  describes it.
- **`vite.config.storefront.ts` hard-codes its output into `theme/ella/`.** The
  `storefront.min.js` / `storefront.min.css` vendor bundle in this theme is a copy of that
  build output. Rebuilding it for Saffron needs a theme argument on that config — a core
  change, not a theme one.
- **A modifier's `price_delta` is charged by nothing.** `validateCart` prices a line from the
  Product and its variant with no concept of a surcharge, so a non-zero delta is shown to the
  customer and then not collected. Keep every answer at `0.00` until paid add-ons are decided;
  the dish sheet prints a debug warning on any dish that has one.
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
