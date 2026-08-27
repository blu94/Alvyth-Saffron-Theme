<?php

namespace Theme\Backend\Support;

use Theme\Backend\Models\Outlet;

/**
 * Which branch this request is browsing, and what that branch serves (register O18a, phase 3).
 *
 * **Why the server needs to know at all.** Phase 2 hid unserved dishes in the browser, which
 * fixed the menu and nothing else: the dish sheet still rendered an Add button for a dish the
 * branch cannot make, and search still returned it. A customer could reach it by link, by search,
 * or by scrolling — and only met the refusal at the payment button, which is the exact experience
 * the whole feature exists to prevent. Hiding a card is presentation; refusing to sell is a
 * decision, and decisions belong on the server.
 *
 * ## Where the branch comes from, and why it is read this way
 *
 * The customer chooses it at the gate, which stores it in `localStorage` and mirrors it into a
 * plain `ovynt_branch` cookie so a normal page load carries it.
 *
 * **Read through `request()->cookie()`, which works because core exempts this cookie from
 * encryption.** `EncryptCookies` decrypts every incoming cookie and silently *drops* the ones it
 * cannot, so a value written by `document.cookie` used to arrive as `null` here — no error, no
 * log. This class therefore read the `$_COOKIE` superglobal instead, which worked but stepped
 * around the framework and was invisible to anything mocking the request.
 *
 * The real fix is one entry in `bootstrap/app.php` — `encryptCookies(except: ['ovynt_branch'])` —
 * and it is now there, so the superglobal read is gone rather than left standing beside it. If
 * that exemption is ever removed, this returns `null` for every visitor and every shop silently
 * shows its whole menu again; {@see tests/Feature/Storefront/Restaurant/BranchMenuTest} is what
 * says so out loud.
 *
 * The value is a customer-supplied integer and is treated as one: it selects an outlet or it
 * selects nothing. There is no trust placed in it — naming a branch can only *narrow* what is
 * offered, never widen it, and `BranchMenuGuard` re-checks the whole basket at checkout against
 * the outlet the **order** names rather than the one the browser claims.
 *
 * ## What it deliberately does not do
 *
 * It does not decide fulfilment, price or hours. It answers one question — *does this branch
 * serve this dish* — and `Outlet::serves()` is what answers it, so an unrestricted branch serves
 * everything without this class needing to know the rule.
 */
class BranchScope
{
    protected static ?Outlet $outlet = null;

    protected static bool $resolved = false;

    /** @var array<int,int>|null */
    protected static ?array $menu = null;

    protected static bool $menuResolved = false;

    /** @var array<int,int>|null */
    protected static ?array $soldOut = null;

    protected static bool $soldOutResolved = false;

    /** The cookie the order gate mirrors its choice into. */
    public const COOKIE = 'ovynt_branch';

    /**
     * The branch being browsed, or null when the customer has not chosen one.
     *
     * Memoised per request: a menu page asks this once per dish, and an outlet lookup per card
     * would turn one page into fifty queries.
     */
    public static function outlet(): ?Outlet
    {
        if (static::$resolved) {
            return static::$outlet;
        }

        static::$resolved = true;
        static::$outlet   = null;

        try {
            // `(int)` on purpose, and it has to survive rubbish rather than merely absence. A
            // browser that still holds an ENCRYPTED value from before the exemption hands back
            // raw ciphertext, not null — so the two failure modes are "missing" and "nonsense",
            // and only the first is an empty string. `(int) 'eyJpdiI6...'` is `0`, which falls
            // through to "no branch chosen" and shows the whole menu, exactly as absence does.
            $id = (int) request()->cookie(self::COOKIE);

            if ($id > 0) {
                static::$outlet = Outlet::query()->active()->find($id);
            }
        } catch (\Throwable $e) {
            // A half-deployed import, a missing table, an unreadable cookie: the shop shows its
            // whole menu, which is what it did before branches could be chosen. Failing open is
            // right here because the checkout guard is what actually holds — and failing closed
            // would blank a working shop's menu over a cookie.
            report($e);

            static::$outlet = null;
        }

        return static::$outlet;
    }

    /**
     * Does the branch being browsed serve this dish?
     *
     * **True when no branch is chosen**, which is every visitor before the gate is answered and
     * every shop that has never used Outlets. "I do not know which branch" and "this branch does
     * not serve it" must never be the same answer — the first shows the whole menu, the second
     * refuses a dish, and collapsing them would empty the menu of every shop on the platform.
     */
    public static function serves(?int $productId): bool
    {
        if ($productId === null || $productId <= 0) {
            return true;
        }

        $outlet = static::outlet();

        return $outlet === null || $outlet->serves($productId);
    }

    /** The branch's name as the customer chose it, for a sentence that has to name it. */
    public static function name(string $locale = 'en'): string
    {
        $outlet = static::outlet();

        if (! $outlet) {
            return '';
        }

        $title = $outlet->title;

        if (is_array($title)) {
            $title = $title[$locale] ?? $title['en'] ?? (count($title) ? reset($title) : '');
        }

        return trim((string) $title) ?: (string) $outlet->slug;
    }

    /**
     * The dish ids to hide from the branch being browsed. Empty is the normal answer.
     *
     * **Exclusive to somebody else.** A dish that names no branches is served everywhere and is
     * never in this list; a dish that names branches is hidden from every branch it does not name.
     * Two queries, both on an indexed column, and the result is the whole answer for the request —
     * so a category page with forty cards asks once rather than forty times.
     *
     * The set difference is done here rather than in SQL deliberately. A `whereNotIn` over a
     * correlated subquery says the same thing, and says it in a form that has to be re-derived and
     * re-read at every one of the five call sites; this way the rule exists once and every caller
     * gets a plain list of ids it cannot misread.
     *
     * @return array<int,int>
     */
    public static function hidden(): array
    {
        if (static::$menuResolved) {
            return static::$menu ?? [];
        }

        static::$menuResolved = true;
        static::$menu         = [];

        try {
            $outlet = static::outlet();

            if ($outlet) {
                // Every dish anybody has made exclusive — where "anybody" is a branch a customer
                // could actually choose.
                //
                // **The join is the whole fix, and without it deactivating a branch deletes its
                // specials from the entire shop.** A dish is in this list because somebody
                // restricted it, and it is then hidden from every branch not named on it. So a
                // dish whose only rows name an inactive or soft-deleted outlet lands in
                // `$restricted` for every active branch and in nobody's `$mine` — hidden
                // everywhere, orderable nowhere, with nothing on any screen saying why.
                //
                // Measured on this shop: no dish is orphaned today, but setting Bangsar to
                // Inactive would orphan six at once (585, 586, 587, 590, 593, 595). One ordinary
                // administrative action, six dishes off the menu.
                //
                // The rows are deliberately left alone rather than cleaned up: `outlets` uses
                // `SoftDeletes` and the pivot's `cascadeOnDelete` does not fire on a soft delete,
                // so the rows outlive the branch **on purpose** — restoring the branch restores
                // its specials. The fix belongs in the readers, never in a sweep that deletes.
                $restricted = \Illuminate\Support\Facades\DB::table('outlet_product as op')
                    ->join('outlets as o', 'o.id', '=', 'op.outlet_id')
                    ->whereNull('o.deleted_at')
                    ->where('o.status', 'active')
                    ->distinct()
                    ->pluck('op.product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                // ...minus the ones this branch is named on.
                $mine = \Illuminate\Support\Facades\DB::table('outlet_product')
                    ->where('outlet_id', $outlet->id)
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                static::$menu = array_values(array_diff($restricted, $mine));
            }
        } catch (\Throwable $e) {
            // Fail open, exactly as `outlet()` does: hide nothing, so the shop shows its whole
            // catalogue — which is what it did before branches could be chosen. `BranchMenuGuard`
            // is what actually holds. The direction matters: failing open here means an empty
            // list, and an empty list restricts nothing, so a broken lookup cannot blank a menu.
            report($e);

            static::$menu = [];
        }

        return static::$menu;
    }

    /**
     * Narrow a product query to what this branch serves.
     *
     * **This is the half that was missing, and it is why category pages went blank.** Phase 2
     * hid unserved dishes in the browser by putting `display: none` on the card — but the card
     * sits inside a grid column the server had already rendered, so the column stayed as an empty
     * slot, the paginator's `total()` still counted the whole catalogue, and a category the branch
     * serves nothing from rendered as a page of nothing under a heading. Measured on this shop:
     * eight of ten categories drew empty for KLCC while the count above them said otherwise.
     *
     * Hiding is presentation and a listing is a query. Constrain the query and the grid, the
     * count and the pager agree by construction rather than by care.
     *
     * The column is qualified by default because two of the five callers build their query from a
     * relation (`$category->products()`), where a bare `id` is ambiguous and MySQL refuses it —
     * the same trap `TableBooking::scopeHolding()` records, where a fail-open catch turned the
     * refusal into "everything is free".
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     * @param  T  $query
     * @return T
     */
    public static function constrain($query, string $column = 'products.id')
    {
        $hidden = static::hidden();

        if ($hidden === []) {
            return $query;
        }

        return $query->whereNotIn($column, $hidden);
    }

    /** Only for tests, which need to drive several branches through one process. */
    public static function flush(): void
    {
        static::$outlet          = null;
        static::$resolved        = false;
        static::$menu            = null;
        static::$menuResolved    = false;
        static::$soldOut         = null;
        static::$soldOutResolved = false;
    }

    /**
     * The dishes this branch has run out of — 86'd — as a plain list of ids.
     *
     * **Deliberately not part of {@see self::hidden()}, though both are "dishes this customer
     * cannot order".** `hidden()` feeds `whereNotIn` and removes a dish from the listing
     * entirely, which is right for a dish this branch is not allowed to sell. An 86'd dish must
     * stay on the menu wearing its sold-out badge: the customer came for it, and a dish that
     * silently vanishes reads as a broken site or a bad memory, while a greyed one reads as a
     * kitchen having a busy night. So this returns a list to grey WITH, never a list to hide BY.
     *
     * One query per request, memoised like `hidden()`, so a category page of forty cards asks
     * once. Fails open — an unreadable cookie or a missing table greys nothing, which shows a
     * dish that cannot be made rather than hiding food the shop can sell.
     *
     * @return array<int,int>
     */
    public static function soldOut(): array
    {
        if (static::$soldOutResolved) {
            return static::$soldOut ?? [];
        }

        static::$soldOutResolved = true;
        static::$soldOut         = [];

        try {
            $outlet = static::outlet();

            if ($outlet) {
                static::$soldOut = \Illuminate\Support\Facades\DB::table('outlet_product_unavailable')
                    ->where('outlet_id', $outlet->id)
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return static::$soldOut ?? [];
    }

    /**
     * Can the branch being browsed make this dish right now?
     *
     * The row-level twin of {@see self::soldOut()}, for a caller holding one dish. True with no
     * branch chosen, and true on any failure: the shop's own `stock` column is the shop-wide
     * answer and is unaffected by this.
     */
    public static function inStock(?int $productId): bool
    {
        if (! $productId) {
            return true;
        }

        return ! in_array((int) $productId, static::soldOut(), true);
    }
}
