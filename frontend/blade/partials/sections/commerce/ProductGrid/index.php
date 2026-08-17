<?php

namespace Theme\Sections\Commerce;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\View;
use Theme\Backend\Support\SectionSetting;
use Theme\Backend\Support\ThemeSettings;

/**
 * Claims core's **Product Grid** section for the menu's own card.
 *
 * This is the block the seeded `/products` page carries, and it was the most off-brand screen
 * in the theme: Bootstrap primary-blue Add buttons on a saffron-and-cream site, prices printed
 * as bare numbers with no `RM`, and a grey "No image" box instead of the theme's dish
 * placeholder — all inside Saffron's own header and footer. Reachable from search and from any
 * link a customer is sent (register E5). Its sibling {@see CollectionGrid} had the same problem
 * on category pages and is claimed the same way.
 *
 * **Core's three filters are kept exactly** — category, availability and product type, all read
 * off the query string so a narrowed listing is a shareable URL and the back button behaves.
 * They work; only their appearance was wrong. The query below mirrors core's so the two cannot
 * disagree about what a filter means, with one addition: `DishCard` reads variants and tags on
 * every card and core loads neither, so rendering core's paginator directly would have cost two
 * queries per dish.
 *
 * **What is deliberately not carried over.** Core's schema declares colour swatches, size
 * grids, brands, a price slider, quick view and compare; core ignores them because no column
 * behind them exists (its `UNSUPPORTED` constant names them). This driver ignores them for the
 * same reason rather than drawing empty boxes — and the theme's own quick view is register E8,
 * where it will be built against the dish sheet rather than invented here.
 */
class ProductGrid
{
    public function render(array $data, string $locale, string $viewPath): string
    {
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        $request = request();
        $limit   = max(1, (int) ($data['products_per_page'] ?? $data['limit'] ?? 12));

        $query = Product::query()
            ->where('status', 'active')
            // Variants are Products too. Listing them puts "Large" beside its own parent, at a
            // slug that does not resolve.
            ->whereNull('productable_id')
            ->with(['variants', 'tags', 'assets']);

        // On a category page this grid is that category's dishes; the page narrows it.
        $page = View::shared('page');

        if ($page instanceof Category) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $page->id));
        }

        // Each filter applies only when present — an absent one must not narrow anything.
        if ($category = $request->query('category')) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $category));
        }

        if ($request->query('availability') === 'in_stock') {
            $query->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0));
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $query = match ($request->query('sort')) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'newest'     => $query->orderByDesc('created_at'),
            default      => $query->orderBy('orders')->orderByDesc('created_at'),
        };

        $dishes   = $query->paginate($limit)->withQueryString();
        $settings = ThemeSettings::all();

        $sidebarPos = $data['sidebar_position'] ?? 'left';
        $showCats   = (bool) ($data['sidebar_show_categories'] ?? true);
        $showAvail  = (bool) ($data['sidebar_show_availability'] ?? true);
        $showType   = (bool) ($data['sidebar_show_product_type'] ?? true);

        $sortOptions = $this->sortOptions();
        $currentSort = (string) $request->query('sort', '');

        return View::make($viewPath, [
            'dishes'       => $dishes,
            'sortOptions'  => $sortOptions,
            'currentSort'  => $currentSort,
            // Resolved here rather than in the template: an unknown `?sort=` value must show
            // the order the query actually ran in, which is the default one.
            'currentSortLabel' => $sortOptions[$currentSort] ?? $sortOptions[''],
            'sidebarPos'   => $sidebarPos,
            // A rail with nothing in it is a column of whitespace, so the layout asks whether
            // any of its three groups will actually draw.
            'hasSidebar'   => $sidebarPos !== 'none' && ($showCats || $showAvail || $showType),
            'showCats'     => $showCats,
            'showAvail'    => $showAvail,
            'showType'     => $showType,
            'showSort'     => (bool) ($data['toolbar_show_sorting'] ?? true),
            'showCount'    => (bool) ($data['toolbar_show_count'] ?? true),
            'gridColClass' => $this->gridColClass($data['columns_desktop'] ?? null, $settings),
            'categories'   => $this->categories($locale),
            'categoriesLabel' => $this->categoriesLabel($locale),
            'types'        => $this->types(),
            'cardSettings' => [
                'layout'           => 'grid',
                'show_price'       => SectionSetting::bool($data['show_price'] ?? null, $settings['dish_show_price'] ?? null, true),
                'show_tags'        => SectionSetting::bool($data['show_tags'] ?? null, $settings['dish_show_tags'] ?? null, true),
                'show_description' => SectionSetting::bool(null, $settings['dish_show_description'] ?? null, true),
                'show_add_button'  => (bool) ($data['show_add_to_cart'] ?? true),
                'add_button_label' => 'Add',
                'sold_out_label'   => $settings['sold_out_label'] ?? '',
            ],
            'locale'       => $locale,
        ])->render();
    }

    /**
     * The four orders the query above can run in, keyed by the `?sort=` value that selects
     * each. Kept identical to {@see CollectionGrid}'s, because the `sort` partial that renders
     * them is shared and a customer moving between the two listings should meet one control.
     *
     * @return array<string,string>
     */
    protected function sortOptions(): array
    {
        return [
            ''           => __('Featured'),
            'price_asc'  => __('Price, low to high'),
            'price_desc' => __('Price, high to low'),
            'newest'     => __('Newest'),
        ];
    }

    /**
     * Categories that actually hold dishes — the same reasoning core records for its own rail:
     * the `type` column is used more loosely than the category form implies, so filtering on it
     * returns an empty list on a real install.
     *
     * @return array<int,array{id:int,title:string}>
     */
    protected function categories(string $locale): array
    {
        return Category::query()
            ->where('status', 'active')
            ->has('products')
            ->orderBy('orders')
            ->get(['id', 'title'])
            ->map(fn (Category $category) => [
                'id'    => $category->id,
                'title' => $this->translate($category->title, $locale),
            ])
            ->filter(fn ($row) => $row['title'] !== '')
            ->values()
            ->all();
    }

    /**
     * What to call the category filter, in the shop's own words.
     *
     * These are core **Categories**, and this rail called them "Menu sections" — the theme's
     * restaurant word for the same record. On a page whose breadcrumb says *Categories* two
     * lines above, that is two names for one thing, and it was noticed immediately. The
     * breadcrumbs already solved this: they title the listing crumb from the shop's own
     * CATEGORIES page, so a restaurant that renamed it "Menu" sees "Menu" with no setting to
     * keep in step. This reads the same record, so the two cannot disagree.
     */
    protected function categoriesLabel(string $locale): string
    {
        static $label = null;

        if ($label !== null) {
            return $label;
        }

        try {
            $page  = Page::where('type', 'CATEGORIES')->where('status', 'active')->first();
            $title = $page ? $this->translate($page->title, $locale) : '';
        } catch (\Throwable $e) {
            $title = '';
        }

        // A shop with no Categories page still needs a heading, and core's own word is the
        // honest fallback — it is what the records are.
        return $label = ($title !== '' ? $title : __('Categories'));
    }

    /** @return array<int,string> */
    protected function types(): array
    {
        return Product::query()
            ->where('status', 'active')
            ->whereNull('productable_id')
            ->whereNotNull('type')
            ->distinct()
            ->pluck('type')
            ->filter()
            ->values()
            ->all();
    }

    /** Two columns on a phone whatever the desktop count, matching the menu. */
    protected function gridColClass(mixed $columns, array $settings): string
    {
        $choice = SectionSetting::choice(
            $columns !== null ? (string) $columns : '',
            (string) ($settings['menu_columns'] ?? ''),
            '3',
        );

        return match ((string) $choice) {
            '2'          => 'col-12 col-sm-6 col-lg-6',
            '4', '5', '6' => 'col-6 col-md-4 col-lg-3',
            default      => 'col-6 col-md-6 col-lg-4',
        };
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
