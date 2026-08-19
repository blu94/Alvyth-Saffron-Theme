<?php

namespace Theme\Backend\Models;

use App\Models\Product;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * The catalogue rule is the operator's, per outlet: `restricts_menu` false — the default, and
 * what every new branch starts as — means this outlet serves the whole menu and the pivot is
 * never consulted. Only when an operator opts in does `products()` become the outlet's menu.
 * Opting *in* rather than out matters: a shop that adds a branch and forgets to tick every dish
 * gets a working branch, not an empty one.
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
        'restricts_menu',
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
        'latitude'        => 'float',
        'longitude'       => 'float',
        'offers_pickup'   => 'boolean',
        'offers_delivery' => 'boolean',
        'restricts_menu'  => 'boolean',
        'is_default'      => 'boolean',
        'orders'          => 'integer',
    ];

    /**
     * The dishes this outlet serves, with any price this branch charges instead.
     *
     * Consulted only when `restricts_menu` is true — see the class note. `price_override` is
     * null on every row until an operator sets one, so attaching a dish never re-prices it.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'outlet_product')
            ->withPivot('price_override')
            ->withTimestamps();
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
     * Does this outlet serve the given dish?
     *
     * An outlet that restricts nothing serves everything — stated here rather than left to each
     * caller, because a caller that forgets the `restricts_menu` check would silently hide the
     * whole menu of every unrestricted branch.
     */
    public function serves(int $productId): bool
    {
        if (! $this->restricts_menu) {
            return true;
        }

        return $this->products()->whereKey($productId)->exists();
    }

    /**
     * What this outlet charges for a dish, or null to use the dish's own price.
     *
     * Null rather than the dish's price on purpose: the caller can then tell "this branch has no
     * opinion" from "this branch charges zero", and only the first should fall through.
     */
    public function priceFor(int $productId): ?float
    {
        $row = $this->products()->whereKey($productId)->first();

        $override = $row?->pivot?->price_override;

        return $override === null ? null : (float) $override;
    }
}
