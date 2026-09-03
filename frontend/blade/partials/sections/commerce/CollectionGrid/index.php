<?php

namespace Theme\Sections\Commerce;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\View;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\SectionSetting;
use Theme\Backend\Support\ThemeSettings;

/**
 * Claims core's **Collection Grid** section for the menu's own card.
 *
 * **What it fixes.** `/products` and any category page carrying this block rendered with no
 * Saffron markup in the content area at all — `card`, `btn btn-sm`, `bg-light`, `col`. Core's
 * generic listing was filling the page inside Saffron's own header and footer: Bootstrap
 * primary-blue Add buttons on a saffron-and-cream site, prices printed as bare numbers
 * (`24.11`, no `RM`) where every other screen prints the shop's currency, and a grey "No image"
 * box instead of the theme's dish placeholder. It was the most off-brand screen in the theme
 * and reachable from search, from the header, and from any link a customer is sent
 * (register E5).
 *
 * `section.blade.php` checks the **theme** class before core's, so shipping this file re-skins
 * every page that already places the block, with no data edit and no re-authoring — the same
 * mechanism {@see ProductDetails} uses to claim the dish page.
 *
 * **The filtering, sorting and pagination are core's and are deliberately kept.** They work:
 * real query-string links, so a narrowed listing is a shareable URL and the back button
 * behaves. This driver mirrors that query rather than inventing one — same scope, same sort
 * map, same `paginate()->withQueryString()` — so the only thing that changes is what the
 * results look like. The one addition is eager loading: `DishCard` reads variants and tags on
 * every card, and core loads neither, so rendering its paginator directly would have issued
 * two queries per dish.
 *
 * Cards resolve their display settings section → theme → built-in through
 * {@see SectionSetting}, exactly as the menu does, so a listing and the menu cannot disagree
 * about whether a dish shows its price.
 */
class CollectionGrid
{
    public function render(array $data, string $locale, string $viewPath): string
    {
        // Core's own Status handling runs before any renderer, but a theme driver is also
        // reached directly by the component resolver, so this stays consistent with the
        // theme's other drivers (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        $limit = max(1, (int) ($data['limit'] ?? 12));
        $page  = View::shared('page');

        // A Category narrows the listing to its own dishes; anything else lists the whole
        // menu. Core shipped this without the `else` for most of its life and rendered
        // "No products found" permanently on the categories index.
        $query = $page instanceof Category
            ? $page->products()->where('products.status', 'active')
            : Product::query()->where('status', 'active');

        // Variants are Products with a parent. Listing them puts "Large" beside the dish it
        // belongs to, at a slug that does not resolve.
        $query->whereNull('productable_id')->with(['variants', 'tags', 'assets']);

        // **The branch narrows the QUERY, not just the cards.** Hiding unserved dishes in the
        // browser left this grid rendering their columns anyway — empty slots that pushed the
        // surviving dishes out of line — while `total()` below still counted the whole catalogue,
        // so the page said "Showing 1-5 of 5" above three dishes. Constraining here makes the
        // grid, the count and the pager one answer instead of three.
        BranchScope::constrain($query);

        $query = match (request()->query('sort')) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'newest'     => $query->orderByDesc('created_at'),
            default      => $query->orderBy('orders')->orderByDesc('created_at'),
        };

        $dishes = $query->paginate($limit)->withQueryString();

        $settings = ThemeSettings::all();

        $sortOptions = $this->sortOptions();
        $currentSort = (string) request()->query('sort', '');

        return View::make($viewPath, [
            'dishes'       => $dishes,
            'sortOptions'  => $sortOptions,
            'currentSort'  => $currentSort,
            // Resolved here rather than in the template: an unknown `?sort=` value must show
            // the order the query actually ran in, which is the default one.
            'currentSortLabel' => $sortOptions[$currentSort] ?? $sortOptions[''],
            'sidebarPos'   => $data['sidebar_position'] ?? 'left',
            'showSort'     => (bool) ($data['show_sort'] ?? true),
            'showFilters'  => (bool) ($data['show_filters'] ?? true),
            'gridColClass' => $this->gridColClass($data['columns_desktop'] ?? null, $settings),
            'collections'  => $this->collections($locale),
            'categoriesLabel' => $this->categoriesLabel($locale),
            'current'      => $page instanceof Category ? $page->id : null,
            'cardSettings' => [
                'layout'           => 'grid',
                'show_price'       => SectionSetting::bool($data['show_price'] ?? null, $settings['dish_show_price'] ?? null, true),
                'show_tags'        => SectionSetting::bool($data['show_tags'] ?? null, $settings['dish_show_tags'] ?? null, true),
                'show_description' => SectionSetting::bool($data['show_description'] ?? null, $settings['dish_show_description'] ?? null, true),
                'show_add_button'  => true,
                // Translated, and it never was: a Malay shop's listing pages printed an
                // English "Add" with no setting anywhere to change it. The card blocks offer
                // their own translatable field; these two commerce grids have none, so the
                // string at least has to go through the translator.
                'add_button_label' => __('Add'),
                'sold_out_label'   => $settings['sold_out_label'] ?? '',
            ],
            'locale'       => $locale,
        ])->render();
    }

    /**
     * The four orders the query above can run in, keyed by the `?sort=` value that selects
     * each. Shared with {@see ProductGrid} through the `sort` partial that renders them, so
     * the two listings offer the same choices in the same words.
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
     * The collection rail beside the grid.
     *
     * Scoped by **having dishes**, not by `type = 'product'` — the same reasoning core's
     * driver records: the column is used more loosely than the category form implies, and a
     * type filter returns an empty rail on a real install. Deriving it from the dishes
     * themselves cannot be wrong about where a customer can actually browse.
     *
     * @return array<int,array{id:int,title:string,url:string}>
     */
    protected function collections(string $locale): array
    {
        // A category this branch serves nothing from is not a destination — it is a link to an
        // empty page. `has('products')` asks whether the CATALOGUE has any; the branch decides
        // whether this customer can order any.
        return Category::query()
            ->where('status', 'active')
            ->whereHas('products', fn ($q) => BranchScope::constrain(
                $q->where('products.status', 'active')->whereNull('products.productable_id')
            ))
            ->orderBy('orders')
            ->get(['id', 'title', 'slug'])
            ->map(fn (Category $category) => [
                'id'    => $category->id,
                'title' => $this->translate($category->title, $locale),
                // The model's accessor, not a hand-built path — it resolves the slug through
                // the locale and its fallback.
                'url'   => $category->store_url,
            ])
            ->filter(fn ($row) => $row['title'] !== '')
            ->values()
            ->all();
    }

    /**
     * What to call the rail, in the shop's own words.
     *
     * These are core **Categories**, and this rail called them "Menu sections" — the theme's
     * restaurant word for the same record — on a page whose breadcrumb says *Categories* two
     * lines above. The breadcrumbs already solved this: they title the listing crumb from the
     * shop's own CATEGORIES page, so a restaurant that renamed it "Menu" sees "Menu" with no
     * setting to keep in step. This reads the same record, so the two cannot disagree.
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

        return $label = ($title !== '' ? $title : __('Categories'));
    }

    /**
     * Two columns on a phone whatever the desktop count, matching the menu — a one-column
     * listing wastes half the screen and a six-column one is unreadable at 375px. Core's
     * schema offers 2–6; the theme's own card is designed around 2, 3 and 4.
     */
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
