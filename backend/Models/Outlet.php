<?php

namespace Theme\Backend\Models;

use App\Models\Product;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * One branch of the shop.
 *
 * **Not a tenant.** ROADMAP 36 scopes every table in core by a `site_id` resolved from the
 * incoming host; this is the narrower thing that was actually asked for — one shop, one
 * catalogue, several places to collect from, and a picker the customer meets inside the pickup
 * flow. A shop with no rows here, or one, behaves exactly as it did before this table existed,
 * which is the property that lets the whole feature ship without a migration of core.
 *
 * **The catalogue rule belongs to the DISH, not to the branch.** Every dish shows at every branch
 * unless somebody has made it exclusive to particular branches; a dish that names branches shows
 * at those and nowhere else. That is one fact stated once, on the dish, which is the only shape
 * that expresses exclusivity honestly — and it is where Toast, Square and Lightspeed put it too.
 *
 * It took two wrong shapes to get here and both are worth remembering. First an **allow-list per
 * branch** behind a `restricts_menu` switch: a branch served only what was ticked, so a branch
 * with four ticked dishes was a four-dish restaurant with eight of ten category pages empty, and
 * every dish added to the catalogue later was silently missing until somebody ticked it again.
 * Then an **exception list per branch**, which fixed the default but still could not say "this
 * dish is KLCC only" — you had to visit every *other* branch and exclude it there, and missing one
 * leaked the dish. Exclusivity was emergent rather than expressible.
 *
 * The pivot is unchanged in shape through all of it. A row means "this dish is available at this
 * branch"; rows exist only for dishes somebody has restricted, and a dish with no rows is
 * available everywhere.
 */
class Outlet extends Model
{
    use HasTranslations;
    use SoftDeletes;
    use LogsSystemActivity;

    protected $fillable = [
        'title',
        'slug',
        'address',
        'phone',
        'latitude',
        'longitude',
        'offers_pickup',
        'offers_delivery',
        'offers_dine_in',
        'delivers_by_own_riders',
        'delivery_radius_km',
        'is_default',
        'timezone',
        'status',
        'orders',
    ];

    public $translatable = ['title'];

    // `title` is deliberately NOT cast to json: `HasTranslations` already serialises a
    // translatable attribute, and `ModifierGroup` declares its translatables the same way.
    //
    // **This is not what caused the "Array to string conversion" 500**, whatever the earlier
    // note here said — removing the cast did not fix it, because the array reaching
    // `Str::slug()` was `slug`, not `title`. Core's `ModuleRequest::prepareForValidation()`
    // was inventing a locale map for a slug this model keeps as a plain string. Fixed there,
    // pinned by `tests/Feature/Admin/Module/ModuleSlugPreparationTest`. The cast stays off on
    // its own merits; do not read its absence as the fix.
    protected $casts = [
        'latitude'               => 'float',
        'longitude'              => 'float',
        'offers_pickup'          => 'boolean',
        'offers_delivery'        => 'boolean',
        'offers_dine_in'         => 'boolean',
        'delivers_by_own_riders' => 'boolean',
        'delivery_radius_km'     => 'float',
        'is_default'             => 'boolean',
        'orders'                 => 'integer',
    ];

    /**
     * The dishes made exclusive to this branch.
     *
     * **Not this branch's menu.** The branch also serves every dish nobody has restricted, which
     * is almost all of them. This relation holds only the exclusives, so an empty one is the
     * normal state and means "this branch has no specials of its own", never "this branch serves
     * nothing". Named for what it holds rather than for the table, because the previous two names
     * both read as "this branch's menu" while meaning something narrower each time.
     */
    public function exclusiveProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'outlet_product')
            ->withTimestamps();
    }

    /**
     * The tables in this branch's dining room (register O19).
     *
     * A dine-in customer is offered **this outlet's** tables and no others, which is the whole
     * point: the shop-wide count it replaces gave every branch the same room. An outlet with no
     * rows here falls back to the old behaviour rather than offering nothing, so a shop that has
     * not filled its tables in is unchanged.
     */
    public function tables(): HasMany
    {
        return $this->hasMany(OutletTable::class);
    }

    /**
     * Service hours belonging to this outlet.
     *
     * `ServiceWindow` already carries a polymorphic `scope`, which the theme uses today to give
     * one menu section its own hours. An outlet is a second thing hours can belong to, and the
     * existing column expresses it with no schema change at all — which is why this feature does
     * not need to touch `service_windows`.
     */
    public function serviceWindows()
    {
        return $this->morphMany(ServiceWindow::class, 'scope');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** In the order an operator arranged them, then oldest first as a stable tie-break. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('orders')->orderBy('id');
    }

    /**
     * Outlets a customer may collect from.
     *
     * The storefront's picker asks for exactly this, and asks it of the database rather than
     * filtering in PHP, because a shop with fifty branches should not load fifty rows to show
     * the six that do pickup.
     */
    public function scopePickup(Builder $query): Builder
    {
        return $query->where('offers_pickup', true);
    }

    public function scopeDelivery(Builder $query): Builder
    {
        return $query->where('offers_delivery', true);
    }

    /**
     * Outlets with a dining room.
     *
     * **Collection is part of the answer, not a separate check a caller may forget.** A dine-in
     * order is recorded as `fulfillment_type = pickup` — a diner needs no address and pays no
     * delivery fee — and the branch is chosen from core's pickup-method list, so a branch that
     * does not collect cannot seat anybody however this column is set. Composing the two here is
     * the same decision {@see self::serves()} makes about the exception list: put the rule where
     * it cannot be half-remembered.
     */
    public function scopeDineIn(Builder $query): Builder
    {
        return $query->where('offers_dine_in', true)->where('offers_pickup', true);
    }

    /**
     * Does this branch seat diners?
     *
     * The row-level twin of {@see self::scopeDineIn()}, for a caller that already holds the
     * outlet and must not pay for a second query to ask one boolean.
     */
    public function dinesIn(): bool
    {
        return (bool) $this->offers_dine_in && (bool) $this->offers_pickup;
    }

    /**
     * Branches whose own riders deliver, and which have drawn how far they go.
     *
     * **Composes `offers_delivery`**, for the reason {@see self::scopeDineIn()} composes
     * `offers_pickup`: a branch that has stopped delivering does not deliver by its own riders
     * either, whatever this column says, and putting the rule here is what stops a second caller
     * half-remembering it. A positive radius is required too — a branch that ticked the switch
     * and left the distance empty has drawn no circle, and treating that as zero would refuse
     * every address in the world while looking configured.
     */
    public function scopeOwnRiders(Builder $query): Builder
    {
        return $query->where('offers_delivery', true)
            ->where('delivers_by_own_riders', true)
            ->whereNotNull('delivery_radius_km')
            ->where('delivery_radius_km', '>', 0);
    }

    /** The row-level twin of {@see self::scopeOwnRiders()}. */
    public function ridesItsOwn(): bool
    {
        return (bool) $this->offers_delivery
            && (bool) $this->delivers_by_own_riders
            && $this->delivery_radius_km !== null
            && (float) $this->delivery_radius_km > 0;
    }

    /**
     * How far this branch is from a point, in kilometres — or null when it cannot say.
     *
     * Haversine, matching core's own `MatchesRegions::haversineKm()` to the metre, because the
     * two answer the same question about the same earth and a shop drawing a ring should not
     * find that the zone which prices the delivery and the branch which cooks it disagree about
     * whether an address is inside it. Duplicated rather than shared because that trait is core's
     * and describes a *zone*; a theme may not widen it, and six lines of arithmetic is a cheaper
     * coupling than a core change.
     *
     * Null when this branch has no point of its own — it has not been geocoded, so it cannot
     * measure anything, and every caller must read that as "no opinion" rather than "out of
     * range".
     */
    public function distanceKmTo(float $lat, float $lng): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $earthKm = 6371.0088;

        $dLat = deg2rad($lat - (float) $this->latitude);
        $dLng = deg2rad($lng - (float) $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $this->latitude)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Do this branch's own riders reach that point?
     *
     * False when the branch cannot say — no radius, or no coordinates of its own. A caller
     * deciding whether to refuse must ask {@see self::ridesItsOwn()} about the shop as a whole
     * first, or a shop that has geocoded nothing would refuse every delivery it has.
     */
    public function deliversTo(float $lat, float $lng): bool
    {
        if (! $this->ridesItsOwn()) {
            return false;
        }

        $distance = $this->distanceKmTo($lat, $lng);

        return $distance !== null && $distance <= (float) $this->delivery_radius_km;
    }

    /**
     * Does this branch serve the given dish?
     *
     * **The question is asked of the DISH first.** A dish nobody has restricted is served
     * everywhere, so the common answer costs one indexed lookup that finds nothing. Only a dish
     * that names branches is narrowed, and then only to the branches it names.
     *
     * Queried on `product_id` rather than through {@see self::exclusiveProducts()} on purpose: the
     * relation answers "is this dish exclusive to ME", and that is the wrong question — a dish
     * exclusive to *another* branch must be hidden here, and a dish exclusive to nobody must not.
     * Those two cases are indistinguishable from this outlet's own rows alone, which is exactly
     * the confusion that made exclusivity impossible to express in the previous two shapes.
     */
    public function serves(int $productId): bool
    {
        // **Only a branch a customer could choose may withhold a dish from the others.** The join
        // is not a tidy-up: without it, a dish whose only rows name an inactive or soft-deleted
        // outlet is refused at every *active* branch — it names branches, so it is exclusive, and
        // it names none of the open ones, so nobody may sell it. Deactivating a branch would
        // silently take its specials off the whole shop. Same rule, same reason, as
        // {@see \Theme\Backend\Support\BranchScope::hidden()}; the two must agree or a dish is
        // hidden from a listing and still sold on its own page.
        //
        // The rows themselves stay. `outlets` soft-deletes and the pivot's `cascadeOnDelete` does
        // not fire on that, which is deliberate — reactivating a branch restores its exclusives.
        $branches = DB::table('outlet_product as op')
            ->join('outlets as o', 'o.id', '=', 'op.outlet_id')
            ->where('op.product_id', $productId)
            ->whereNull('o.deleted_at')
            ->where('o.status', 'active')
            ->pluck('op.outlet_id');

        // Restricted to nobody who is open, so served by everybody. The overwhelming majority of
        // dishes are here because nobody ever restricted them at all.
        if ($branches->isEmpty()) {
            return true;
        }

        return $branches->contains($this->id);
    }

    /**
     * The dishes this branch cannot make right now — 86'd, in a kitchen's own word.
     *
     * **Not the same question as {@see self::serves()}, and the two must never be folded.**
     * `serves()` asks a catalogue question the DISH owns: which branches are allowed to sell
     * this at all. This asks a service question the BRANCH owns: what did we run out of
     * tonight. Opposite owners, opposite lifetimes, and opposite answers on screen — an
     * unserved dish is hidden here, an 86'd one stays on the menu greyed with its sold-out
     * badge, because somebody who came for it should learn it is off rather than wonder
     * whether they imagined it.
     */
    public function unavailableProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'outlet_product_unavailable')
            ->withTimestamps();
    }

    /**
     * Has this branch 86'd this dish?
     *
     * One indexed lookup on the pair, and the common answer is "no" — most dishes are on most
     * nights. Deliberately does NOT consult the branch's status: unlike exclusivity, a closed
     * branch's row withholds nothing from anybody, because the only thing that reads this is
     * a customer already scoped to this branch.
     */
    public function hasRunOutOf(int $productId): bool
    {
        return DB::table('outlet_product_unavailable')
            ->where('outlet_id', $this->id)
            ->where('product_id', $productId)
            ->exists();
    }

    // `priceFor()` lived here and is gone. It read `outlet_product.price_override`, a column that
    // only ever existed on a row meaning "this dish is exclusive to this branch" — so it could
    // price a branch's specials and never one of the many dishes a branch shares, which is
    // incoherent as a pricing model rather than merely incomplete. Nothing called it. Per-branch
    // pricing gets `outlet_product_price`, whose rows mean only what their own name says; see the
    // migration.
}
