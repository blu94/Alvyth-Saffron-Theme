<?php

namespace Theme\Components;

use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\ThemeSettings;

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
        // Passed in by the section from the theme's Restaurant tab; the fallback is the same
        // phrase the dish sheet uses, so a card and its sheet never disagree on the wording.
        $soldOutLabel    = $this->translate($settings['sold_out_label'] ?? '', $locale) ?: __('Sold out for today');
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

        // What the branch being browsed charges, if it charges its own price for this dish.
        //
        // Applied as a shift to whatever figure was resolved above — the parent's price or the
        // cheapest size — so a "from" price stays a "from" price and every size keeps its own
        // premium. The same number `ModifierPricing` adds at the cart, from the same resolver,
        // because a menu that advertises one price and a cart that charges another is the worst
        // failure this feature could have.
        $shift = BranchScope::priceShift((int) $dish->id, $basePrice);

        if (abs($shift) > 0.0001) {
            $displayPrice = max(0.0, $displayPrice + $shift);
            $basePrice    = max(0.0, $basePrice + $shift);
        }

        $comparePrice = $dish->data['compare_at_price'] ?? null;
        $comparePrice = is_numeric($comparePrice) ? (float) $comparePrice : null;
        $hasDiscount  = $comparePrice !== null && $comparePrice > $displayPrice;

        // Tags become the dietary and heat badges — Spicy, New, Halal, Vegan.
        //
        // One, and this was walked down from three.
        //
        // They are drawn over the photograph, and the row has to stop short of the save heart,
        // which leaves about 124px on a 203px card. Three chips stacked four rows deep and
        // covered the image; two still stacked, and each one truncated mid-word — "Dummy
        // TypeScript" wants 128px and had 123. Measured on the live page, not guessed.
        //
        // So the fix is fewer labels rather than smaller ones: a single tag gets the whole
        // width and almost never truncates, and one word over a photo is what a menu card can
        // actually carry. The dish sheet is where a dish lists everything it is.
        $tags = $showTags
            ? $dish->tags
                ->map(fn ($tag) => $this->translate($tag->title, $locale))
                ->filter()
                ->take(1)
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

        // ...and the branch this customer is browsing may have run out tonight, whatever the
        // shop-wide count says. Applied last and only ever downward: a dish 86'd here is sold
        // out here, and no amount of stock elsewhere puts it back. The card keeps its place on
        // the menu and wears the badge — the whole distinction from an exclusive dish, which is
        // hidden instead, because somebody who came for tonight's special should learn it is off
        // rather than wonder whether they imagined it.
        if ($isAvailable && ! BranchScope::inStock((int) $dish->id)) {
            $isAvailable = false;
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

        // Read from the theme rather than taken from $settings like the display switches
        // above, because Saved Dishes is one feature with one switch — the card heart, the
        // dish sheet heart, the header link and the page all answer to it — and there is no
        // per-block override to resolve. Passing it through every caller's $cardSettings
        // would be three places to forget it, which is exactly how `sold_out_label` came to
        // be read by a card that never received it (audit A7).
        $showWishlist = ThemeSettings::bool('wishlist_enabled', true);

        // Quick view (register E8). Theme-level for the same reason as the wishlist above: it
        // is one feature with one switch, and threading it through every caller's
        // `$cardSettings` would be four more places to forget it.
        //
        // It deliberately does **not** configure the dish. A quick view that carries sizes and
        // compulsory questions would be the dish sheet written a second time, and the two
        // copies would drift on the one screen where drift costs money. So the dialog is a
        // closer look — every photograph, the full description, the labels — and the ordering
        // decision stays where `$canQuickAdd` already puts it: added straight from the card
        // when there is nothing to decide, and handed to the sheet when there is. That is the
        // same line the saved-dishes list draws, and for the same reason (audit A1).
        $showQuickView = ThemeSettings::bool('quick_view_enabled', true);

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
                // Carried for the saved-dishes list, which is rendered entirely from
                // localStorage and has no other way back to the dish. Core's addToCart
                // copies id/title/price/image by name, so the extra key is inert there.
                'url'   => $url,
            ],
            'labels' => [
                // The dish name is never interpolated into a JS string literal — the menu
                // will eventually contain a "Chef's Special".
                'add'     => trim($addButtonLabel . ' ' . $title),
                'added'   => __('Added to your order'),
                'save'    => __('Save this dish'),
                'unsave'  => __('Remove from saved dishes'),
                'quickView'  => __('Take a closer look'),
                'closeQuick' => __('Close'),
                'viewDish'   => __('View dish'),
                'thumbnail'  => __('Show photo :n', ['n' => ':n']),
            ],
            // Every photograph, for the quick view's gallery. The card itself still shows one.
            'images' => $showQuickView ? $this->galleryUrls($dish) : [],
            'quick'  => [
                'description' => trim(strip_tags((string) $this->translate($dish->description, $locale))),
                'tags'        => $tags,
                'price'       => $this->formatMoney($displayPrice, $currencySymbol, $currencyPosition),
                'fromPrice'   => $isFromPrice,
                'available'   => $isAvailable,
                'canAdd'      => $canQuickAdd,
                'soldOut'     => $soldOutLabel,
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
            'showWishlist'     => $showWishlist,
            'showQuickView'    => $showQuickView,
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

    /**
     * Every photograph of this dish, featured first — the quick view's gallery.
     *
     * Same resolution order as `DishSheet::galleryUrls()` on purpose: a customer who opens the
     * quick view and then the dish page should see the photographs in the same order, and the
     * duplicate is two short methods rather than a shared trait a theme would have to publish.
     * `assets` is already eager-loaded, so this issues no query.
     *
     * @return array<int,string>
     */
    protected function galleryUrls(mixed $dish): array
    {
        $assets = $dish->assets->where('usage', 'PRODUCT_IMAGE')->sortByDesc('featured');

        if ($assets->isEmpty()) {
            $assets = $dish->assets;
        }

        return $assets
            ->map(function ($asset) {
                $path = (string) $asset->path;

                if ($path === '') {
                    return '';
                }

                return Str::startsWith($path, ['http://', 'https://', '/'])
                    ? $path
                    : '/storage/' . ltrim($path, '/');
            })
            ->filter()
            // The same file attached twice would give the gallery two identical slides and a
            // thumbnail strip that highlights both.
            ->unique()
            ->values()
            ->all();
    }
}
