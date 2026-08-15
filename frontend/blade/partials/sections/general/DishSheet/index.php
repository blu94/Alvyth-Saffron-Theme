<?php

namespace Theme\Sections\General;

use App\Models\Product;
use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Models\ModifierGroup;

/**
 * The configure-and-add block — the single most-used screen in the theme.
 *
 * Composes, in order: size (from product variants), the dish's modifier groups as radios or
 * checkboxes honouring min/max, special instructions, quantity, and a live running total.
 *
 * Everything the customer picks becomes ONE FLAT, STRING-VALUED options object on the cart
 * line:
 *
 *     { Size: 'Large', Side: 'Fries', Extras: 'Cheese, Bacon', Notes: 'No onions' }
 *
 * Flat and string-valued on purpose: that is exactly what `validateCart` normalises, ksorts
 * and dedups, and what lands in `OrderItem.meta['options']` for the kitchen to read.
 *
 * The section resolves its dish from the page being rendered, so dropping it on a Product
 * page needs no configuration. `data.product_id` overrides that for a landing page pinned to
 * one dish.
 */
class DishSheet
{
    public function __construct(
        protected ApplicationInterface $appSettingsRepo
    ) {}

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $dish = $this->resolveDish($data);

        if (!$dish) {
            return View::make($themeViewPath, [
                'dish'        => null,
                'uid'         => 'dish-sheet-empty',
                'locale'      => $locale,
                'data'        => $data,
                'groups'      => [],
                'variants'    => [],
                'payload'     => [],
                'heading'     => '',
                'description' => '',
                'notesLabel'  => '',
                'notesMax'    => 0,
                'showNotes'   => false,
                'isAvailable' => false,
                'soldOutLabel' => '',
                'hasUnpricedModifiers' => false,
            ])->render();
        }

        $appSettings      = $this->appSettingsRepo->getSettings();
        $currencySymbol   = $appSettings['currency_symbol'] ?? '$';
        $currencyPosition = $appSettings['currency_position'] ?? 'prefix';

        $title       = $this->translate($dish->title, $locale);
        $description = trim(strip_tags((string) $this->translate($dish->description, $locale)));

        // ── Sizes ───────────────────────────────────────────────────────────────
        // A variant IS a Product whose productable_id points at the parent, so its own
        // translatable title is the size label. Inactive variants are dropped rather than
        // rendered disabled: a size the kitchen has withdrawn is not a choice.
        //
        // A sold-out size is different: it stays on the sheet, disabled, because the customer
        // needs to see that Large exists and is gone rather than wonder whether the shop ever
        // sold it. `stock` is null for unlimited, so only a real `0` disables anything.
        $variants = $dish->variants
            ->where('status', 'active')
            ->map(function (Product $variant) use ($locale) {
                return [
                    'id'        => $variant->id,
                    'label'     => $this->variantLabel($variant, $locale),
                    'price'     => (float) ($variant->price ?? 0),
                    'available' => $variant->stock === null || (int) $variant->stock > 0,
                ];
            })
            ->values()
            ->all();

        // ── Modifier groups ─────────────────────────────────────────────────────
        $groups = $this->resolveGroups($dish, $locale);

        // Any non-zero price_delta reaching the browser would show the customer a total the
        // server will not charge. Flagged so the template can say so in debug rather than
        // quietly lying about the price. See RESTAURANT-THEME-SPEC.md §2.3 / §17.3.
        $hasUnpricedModifiers = collect($groups)
            ->flatMap(fn ($g) => $g['modifiers'])
            ->contains(fn ($m) => $m['price_delta'] !== 0.0);

        // Sold out when nothing on the sheet can be bought. With sizes that means every size
        // is gone; without them it is the dish's own stock. `status` still gates publication,
        // and core refuses a zero-stock line in `validateCart`, so the button being disabled
        // here and the server refusing there are the same rule stated twice rather than two
        // rules that can drift.
        $sellable = collect($variants)->contains(fn ($variant) => $variant['available']);

        $isAvailable = ($dish->status ?? 'active') === 'active'
            && ($variants === []
                ? ($dish->stock === null || (int) $dish->stock > 0)
                : $sellable);

        $notesMax    = (int) ($data['notes_max'] ?? 140);

        $payload = [
            'dish' => [
                'id'    => $dish->id,
                'title' => $title,
                'price' => (float) ($dish->price ?? 0),
                'image' => $this->primaryImageUrl($dish),
            ],
            'variants' => $variants,
            'groups'   => $groups,
            'currency' => [
                'symbol'   => $currencySymbol,
                'position' => $currencyPosition,
            ],
            'labels' => [
                'notesKey' => (string) ($data['notes_key'] ?? 'Notes'),
                'sizeKey'  => (string) ($data['size_key'] ?? 'Size'),
                'added'    => __('Added to your order'),
                'chooseOne' => __('Choose one'),
                'required' => __('Required'),
                'optional' => __('Optional'),
                'upTo'     => __('Choose up to :n', ['n' => ':n']),
                // The remaining strings the JS shows. Translated here, with everything else,
                // rather than hardcoded in the script — a Malay menu was showing three
                // English error lines because these lived in JS string literals.
                'chooseAtLeast'    => __('Choose at least :n', ['n' => ':n']),
                'answerHighlighted' => __('Please answer the highlighted questions.'),
                'cartUnavailable'  => __('The cart is unavailable. Please reload the page.'),
            ],
            'notesMax'  => $notesMax,
            'maxQty'    => max(1, (int) ($data['max_quantity'] ?? 20)),
            'available' => $isAvailable,
        ];

        return View::make($themeViewPath, [
            'dish'                 => $dish,
            'uid'                  => 'dish-sheet-' . $dish->id . '-' . Str::random(6),
            'heading'              => $title,
            'description'          => $description,
            'variants'             => $variants,
            'groups'               => $groups,
            'payload'              => $payload,
            'showNotes'            => (bool) ($data['show_notes'] ?? true),
            'notesLabel'           => $this->translate($data['notes_label'] ?? '', $locale) ?: __('Special instructions'),
            'notesMax'             => $notesMax,
            'isAvailable'          => $isAvailable,
            'soldOutLabel'         => $this->translate($data['sold_out_label'] ?? '', $locale) ?: __('Sold out for today'),
            'hasUnpricedModifiers' => $hasUnpricedModifiers,
            'imageUrl'             => $this->primaryImageUrl($dish),
            'locale'               => $locale,
            'data'                 => $data,
        ])->render();
    }

    /**
     * The dish this sheet configures.
     *
     * `View::shared('page')` is how a section reaches the record ThemeController resolved.
     * On a Product page that is the dish itself; anywhere else the section must name one.
     */
    protected function resolveDish(array $data): ?Product
    {
        $eager = ['variants', 'tags', 'assets'];

        if (!empty($data['product_id'])) {
            return Product::with($eager)->whereKey((int) $data['product_id'])->whereNull('productable_id')->first();
        }

        $page = View::shared('page') ?? null;

        if ($page instanceof Product) {
            return $page->loadMissing($eager);
        }

        return null;
    }

    /**
     * The dish's modifier groups, in pivot order, with their answers.
     *
     * `required_override` on the pivot wins over the group's own `min_select`, so the same
     * group can be compulsory on one dish and optional on another without being duplicated.
     */
    protected function resolveGroups(Product $dish, string $locale): array
    {
        // The table ships with this theme's migrations. If a storefront renders before they
        // have run — a half-deployed import, a stale cache — degrade to no groups rather than
        // throwing on a missing table and taking the whole dish page down.
        try {
            $groups = ModifierGroup::query()
                ->where('modifier_groups.status', 'active')
                ->whereHas('dishes', fn ($q) => $q->whereKey($dish->id))
                ->with(['modifiers' => fn ($q) => $q->where('status', 'active')])
                ->join('dish_modifier_group', 'dish_modifier_group.modifier_group_id', '=', 'modifier_groups.id')
                ->where('dish_modifier_group.product_id', $dish->id)
                ->orderBy('dish_modifier_group.orders')
                ->select('modifier_groups.*', 'dish_modifier_group.required_override as pivot_required_override')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return $groups->map(function (ModifierGroup $group) use ($locale) {
            $override = $group->pivot_required_override;
            $override = $override === null ? null : (bool) $override;

            $required = $group->isRequired($override);
            $max      = $group->effectiveMaxSelect();

            return [
                'id'          => $group->id,
                'slug'        => $group->slug,
                // The options-bag key. It is the group's TITLE, not its slug, because this
                // string is what the kitchen reads off the ticket and what the customer sees
                // on the receipt — a slug would be an internal identifier leaking onto both.
                'key'         => $this->translate($group->title, $locale) ?: $group->slug,
                'title'       => $this->translate($group->title, $locale) ?: $group->slug,
                'description' => trim(strip_tags((string) $this->translate($group->description, $locale))),
                'selection'   => $group->selection,
                'required'    => $required,
                'min_select'  => $required ? max(1, (int) $group->min_select) : 0,
                'max_select'  => $max,
                'modifiers'   => $group->modifiers->map(fn (\Theme\Backend\Models\Modifier $m) => [
                    'id'          => $m->id,
                    'label'       => $this->translate($m->title, $locale),
                    'price_delta' => (float) $m->price_delta,
                    'is_default'  => (bool) $m->is_default,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * A variant's display label. Falls back to its option data, then its SKU — a size picker
     * showing "Product 4" because a title was never set is worse than showing the SKU.
     */
    protected function variantLabel(Product $variant, string $locale): string
    {
        $title = $this->translate($variant->title, $locale);

        if ($title !== '') {
            return $title;
        }

        $optionValues = collect($variant->data ?? [])
            ->filter(fn ($v, $k) => is_scalar($v) && !in_array($k, ['compare_at_price', 'vendor'], true))
            ->values()
            ->all();

        if (!empty($optionValues)) {
            return implode(' / ', array_map('strval', $optionValues));
        }

        return (string) ($variant->sku ?: '—');
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }

    protected function primaryImageUrl(Product $dish): string
    {
        $asset = $dish->assets->where('usage', 'PRODUCT_IMAGE')->sortByDesc('featured')->first()
            ?? $dish->assets->first();

        if (!$asset) {
            return '';
        }

        $path = (string) $asset->path;

        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/']) ? $path : '/storage/' . ltrim($path, '/');
    }
}
