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
| 5 | Kitchen queue + dish availability screens | **done** — see the note on the queue below |
| 6–8 | Core §14 items 1–6 | not started (core) |
| 9 | Notifications, order tracker, receipts, reorder | not started |
| 10 | RBAC, `.agent/docs/restaurant.md` | docs **done**; `kitchen` resource outstanding |

**Sections:** `MenuSections`, `DishGrid`, `DishSheet`, `StoreStatus`, `PromoStrip`,
`OutletInfo`, `FaqAccordion`.
**Admin modules:** `modifier-groups`, `service-windows` (weekly hours *and* dated holidays in
one list, plus the Kitchen Queue page).
**Extends:** `products` — a Modifiers tab on the dish's own form, via core's module extension
seam (`admin/extends/products.json` + `backend/Handlers/ProductModifierGroups.php`).
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
├── settings/                 index.json → $ref general / header / footer / restaurant
├── modules/                  own admin screens (modifier-groups, service-windows, …)
├── extends/                  tabs added to a module core owns — {type}.json
└── sections/                 one JSON schema per page-builder section
backend/
├── Handlers/                 writes the keys admin/extends declares
├── Models/, Repositories/, migrations/
frontend/
├── blade/
│   ├── layout.blade.php      master layout (Vue CDN, theme CSS, header/footer)
│   ├── pages/                one template per core page type
│   └── partials/
│       ├── layout/           header, footer, dynamic-styles
│       ├── components/       {Name}/index.blade.php + index.php
│       └── sections/general/ {Name}/index.blade.php + index.php
└── assets/{css,js}
```

## Development

Import into the running dev stack — packages, transports, extracts, migrates and activates:

```powershell
.\scripts\import-theme.ps1 -Theme saffron
```

Run from the **repository root**, not from this directory. SCSS is compiled by that script
via `npx sass`; `frontend/assets/css/theme.css` is a build output and must be regenerated
after editing any `.scss` file, because it is what gets packaged.

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
  **delivery-only** or **pickup-only** correctly, and the mode picker stays presentational.
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
- **The kitchen queue is a list, not a board.** The admin schema engine has no board or
  card-list field type and a theme may not ship admin Vue, so the queue renders from the field
  types that exist. The repository already returns the data shaped as columns, so a board
  renderer can be dropped in without touching it.
- **The preview is `preview.png`, not `preview.webp`.** No WebP encoder exists on the container
  or the host — GD is built without it, and there is no `imagick`, `cwebp`, `magick` or
  `ffmpeg`. `create-theme.md` step 6 permits either format. WebP would need the browser-canvas
  `toDataURL('image/webp')` route.

## Licence

Proprietary. © Ovynt.
