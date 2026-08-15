<?php

namespace Theme\Components;

use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * One dish on the menu.
 *
 * A dish is a core Product — the spec deliberately models no parallel catalogue, so
 * everything here reads from Product, its variants, its tags and its assets.
 *
 * Expects $data['dish'] to be a Product with `variants`, `tags` and `assets` already
 * eager-loaded; ProductRepository::baseIndexQuery() loads all three. Nothing in this
 * class issues a query of its own except the application settings lookup, so a menu of
 * sixty dishes stays at a fixed query count.
 */
class DishCard
{
    public function __construct(
        protected ApplicationInterface $appSettingsRepo
    ) {}

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $dish = $data['dish'] ?? null;

        if (!$dish) {
            return '';
        }

        $settings = $data['settings'] ?? [];

        $showPrice       = $settings['show_price'] ?? true;
        $showTags        = $settings['show_tags'] ?? true;
        $showDescription = $settings['show_description'] ?? true;
        $showAddButton   = $settings['show_add_button'] ?? true;
        $addButtonLabel  = $this->translate($settings['add_button_label'] ?? 'Add', $locale) ?: 'Add';
        $soldOutLabel    = $this->translate($settings['sold_out_label'] ?? 'Sold out', $locale) ?: 'Sold out';
        $layout          = $settings['layout'] ?? 'grid';

        $appSettings      = $this->appSettingsRepo->getSettings();
        $currencySymbol   = $appSettings['currency_symbol'] ?? '$';
        $currencyPosition = $appSettings['currency_position'] ?? 'prefix';

        $title = $this->translate($dish->title, $locale);
        $url   = $dish->store_url;

        $description = $showDescription
            ? Str::limit(strip_tags((string) $this->translate($dish->description, $locale)), 110)
            : '';

        // Prices. A dish sized Regular / Large is one Product with variants, and the card
        // must show the cheapest as a "from" price rather than the parent's own figure —
        // which may not correspond to anything the customer can actually buy.
        $basePrice     = (float) ($dish->price ?? 0);
        $variantPrices = $dish->variants
            ->where('status', 'active')
            ->map(fn ($variant) => (float) ($variant->price ?? 0))
            ->filter(fn ($price) => $price > 0)
            ->values();

        $displayPrice = $basePrice;
        $isFromPrice  = false;

        if ($variantPrices->isNotEmpty()) {
            $displayPrice = (float) $variantPrices->min();
            $isFromPrice  = $variantPrices->unique()->count() > 1
                || ($basePrice > 0 && abs($basePrice - $displayPrice) > 0.001);
        }

        $comparePrice = $dish->data['compare_at_price'] ?? null;
        $comparePrice = is_numeric($comparePrice) ? (float) $comparePrice : null;
        $hasDiscount  = $comparePrice !== null && $comparePrice > $displayPrice;

        // Tags become the dietary and heat badges — Spicy, New, Halal, Vegan.
        $tags = $showTags
            ? $dish->tags
                ->map(fn ($tag) => $this->translate($tag->title, $locale))
                ->filter()
                ->take(3)
                ->values()
                ->all()
            : [];

        $image = $this->primaryImageUrl($dish);

        // Availability is `status` **and** stock, and the two answer different questions.
        //
        // `status` is publication: a draft dish is not on the menu at all, and the section
        // query has already excluded it before this runs. `stock` is availability: null is
        // unlimited, a number is what is left, and `0` is sold out — the dish stays on the
        // menu, greyed, with its badge, which is the whole point of the distinction.
        //
        // Reading `status` alone is what made the sold-out badge below unreachable: the only
        // way to mark a dish unavailable was to draft it, and a drafted dish never reached
        // this card. Core now refuses a zero-stock line in `validateCart`, so this and the
        // server agree on what "sold out" means.
        $stock       = $dish->stock;
        $inStock     = $stock === null || (int) $stock > 0;
        $isAvailable = ($dish->status ?? 'active') === 'active' && $inStock;

        // A dish sized Regular / Large is available while **any** size still is. Judging the
        // parent's own stock would black out the whole dish because Large ran out.
        $sellableVariants = $dish->variants
            ->where('status', 'active')
            ->filter(fn ($variant) => $variant->stock === null || (int) $variant->stock > 0);

        if ($dish->variants->where('status', 'active')->isNotEmpty()) {
            $isAvailable = ($dish->status ?? 'active') === 'active' && $sellableVariants->isNotEmpty();
        }

        // Only a dish the customer has nothing to decide about can be added straight from
        // the card. Two kinds of decision exist: a size (variants) and a required modifier
        // group ("Choose your side", min_select >= 1 or a per-dish override). The first
        // version checked only variants, so a dish with a compulsory group was quick-added
        // with an empty options bag and the kitchen received an order with no answers —
        // checkout does not refuse it (§14 item 4 is core-blocked), so the card is the gate.
        $canQuickAdd = $isAvailable
            && $dish->variants->where('status', 'active')->isEmpty()
            && ! $this->hasRequiredModifierGroup($dish->id);

        $uid = 'dish-card-' . $dish->id . '-' . Str::random(6);

        // Assembled here rather than as an array literal inside `@json(...)` in the
        // template. Blade's json directive splits its argument on top-level commas to find
        // its optional $options and $depth parameters, so a multi-element array literal is
        // silently compiled into json_encode($first, $second, $third) — wrong when it
        // parses at all. One variable in, one JSON object out.
        $jsPayload = [
            'dish' => [
                'id'    => $dish->id,
                'title' => $title,
                'price' => $displayPrice,
                'image' => $image !== '' ? $image : null,
            ],
            'labels' => [
                // The dish name is never interpolated into a JS string literal — the menu
                // will eventually contain a "Chef's Special".
                'add'   => trim($addButtonLabel . ' ' . $title),
                'added' => __('Added to your order'),
            ],
        ];

        return View::make($themeViewPath, [
            'dish'             => $dish,
            'uid'              => $uid,
            'jsPayload'        => $jsPayload,
            'title'            => $title,
            'url'              => $url,
            'description'      => $description,
            'image'            => $image,
            'tags'             => $tags,
            'layout'           => $layout,
            'showPrice'        => $showPrice,
            'showAddButton'    => $showAddButton,
            'addButtonLabel'   => $addButtonLabel,
            'soldOutLabel'     => $soldOutLabel,
            'isAvailable'      => $isAvailable,
            'canQuickAdd'      => $canQuickAdd,
            'isFromPrice'      => $isFromPrice,
            'displayPrice'     => $displayPrice,
            'formattedPrice'   => $this->formatMoney($displayPrice, $currencySymbol, $currencyPosition),
            'formattedCompare' => $hasDiscount ? $this->formatMoney($comparePrice, $currencySymbol, $currencyPosition) : null,
            'locale'           => $locale,
            'data'             => $data,
        ])->render();
    }

    /**
     * Every dish id with at least one effective-required modifier group, resolved once per
     * request whatever the card count — this class promises a menu of sixty dishes stays at
     * a fixed query count, and an exists() per card would quietly break that.
     *
     * "Effective" honours the per-dish pivot override the same way the dish sheet does:
     * an override of true requires the group regardless of min_select; null defers to the
     * group's own min_select >= 1 (ModifierGroup::isRequired()).
     *
     * @var array<int, true>|null
     */
    protected static ?array $requiredGroupDishIds = null;

    protected function hasRequiredModifierGroup(int $dishId): bool
    {
        if (static::$requiredGroupDishIds === null) {
            // Same degrade as DishSheet::resolveGroups(): the tables ship with this theme's
            // migrations, and a storefront rendering before they run must lose quick-add
            // gating, not the whole menu.
            try {
                static::$requiredGroupDishIds = DB::table('dish_modifier_group')
                    ->join('modifier_groups', 'modifier_groups.id', '=', 'dish_modifier_group.modifier_group_id')
                    ->where('modifier_groups.status', 'active')
                    ->where(function ($query) {
                        $query->where('dish_modifier_group.required_override', true)
                            ->orWhere(function ($q) {
                                $q->whereNull('dish_modifier_group.required_override')
                                  ->where('modifier_groups.min_select', '>=', 1);
                            });
                    })
                    ->pluck('dish_modifier_group.product_id')
                    ->flip()
                    ->all();
            } catch (\Throwable $e) {
                report($e);
                static::$requiredGroupDishIds = [];
            }
        }

        return isset(static::$requiredGroupDishIds[$dishId]);
    }

    /**
     * Resolve a translatable attribute that may arrive as a raw string or a locale map.
     */
    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }

    protected function formatMoney(float $amount, string $symbol, string $position): string
    {
        $formatted = number_format($amount, 2);

        return $position === 'suffix' ? $formatted . $symbol : $symbol . $formatted;
    }

    /**
     * The dish photo, taken from the already-loaded asset collection so a menu does not
     * issue one query per card. `Asset::path` is an accessor returning a full URL.
     */
    protected function primaryImageUrl(mixed $dish): string
    {
        $asset = $dish->assets
            ->where('usage', 'PRODUCT_IMAGE')
            ->sortByDesc('featured')
            ->first()
            ?? $dish->assets->first();

        if (!$asset) {
            return '';
        }

        $path = (string) $asset->path;

        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/'])
            ? $path
            : '/storage/' . ltrim($path, '/');
    }
}
