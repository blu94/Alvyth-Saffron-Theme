<?php

namespace Theme\Components;

use App\Models\Category;
use App\Models\Product;
use App\Repositories\Category\CategoryInterface;
use App\Repositories\Product\ProductInterface;
use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * The rail beside the dish sheet on layouts 08 and 09 — Saffron's answer to Ella's
 * `ProductSidebar`.
 *
 * It exists because core's **Product Details** block ships two switches, *Show Categories* and
 * *Show Featured Products Mini-List*, that had nothing to drive on this theme: Saffron claimed
 * the block's renderer and ignored its schema, so an operator could set them, save, reload, and
 * watch the page not change. The rail is the thing those switches now turn on and off.
 *
 * **Courses, not every category.** `MenuSections` established the rule this reuses: a menu
 * section is a category that actually holds active dishes, and `type = product` is applied
 * afterwards as a *preference* rather than a filter — seeded and older shops carry `general` on
 * categories full of dishes, and dropping them would leave those shops with an empty rail. Where
 * any properly typed category exists, only those are listed.
 *
 * **The mini-list excludes variants and the dish being viewed.** `baseIndexQuery` already scopes
 * to `productable_id IS NULL`, so a Large portion never appears as its own entry; the current
 * dish is dropped here, because a rail on the Chicken Rice page that recommends Chicken Rice is
 * a dead link back to itself.
 */
class DishSidebar
{
    /** How many entries each panel shows before it stops. Ella's rail uses 10 and 5. */
    private const MAX_COURSES = 10;
    private const MAX_DISHES  = 5;

    public function __construct(
        protected ApplicationInterface $appSettingsRepo,
        protected CategoryInterface $categoryRepo,
        protected ProductInterface $productRepo
    ) {}

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $showCourses = (bool) ($data['show_categories'] ?? true);
        $showDishes  = (bool) ($data['show_featured'] ?? true);

        // Both off is not an empty rail — the sheet asks first and renders no column at all, so
        // reaching here with nothing to draw would print a stray border. Returning early keeps
        // the two switches honest in the one case where they cancel each other out.
        if (! $showCourses && ! $showDishes) {
            return '';
        }

        $appSettings      = $this->appSettingsRepo->getSettings();
        $currencySymbol   = $appSettings['currency_symbol'] ?? '$';
        $currencyPosition = $appSettings['currency_position'] ?? 'prefix';

        $currentId = (int) ($data['current_id'] ?? 0);

        return View::make($themeViewPath, [
            'showCourses' => $showCourses,
            'showDishes'  => $showDishes,
            'courses'     => $showCourses ? $this->courses($locale) : [],
            'dishes'      => $showDishes ? $this->dishes($locale, $currentId, $currencySymbol, $currencyPosition) : [],
            'locale'      => $locale,
        ])->render();
    }

    /**
     * The shop's courses, each linking to its own menu page.
     *
     * @return array<int, array{title: string, url: string, count: int}>
     */
    protected function courses(string $locale): array
    {
        try {
            $categories = $this->categoryRepo
                ->baseIndexQuery(['status' => 'active'])
                ->whereHas('products', function ($query) {
                    $query->where('products.status', 'active')
                        ->whereNull('products.productable_id');
                })
                ->withCount(['products' => function ($query) {
                    $query->where('products.status', 'active')
                        ->whereNull('products.productable_id');
                }])
                ->orderBy('orders')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            // A rail is decoration; the ordering form beside it is not. Degrade to an empty
            // panel rather than taking the dish page down with it.
            report($e);

            return [];
        }

        $typed = $categories->where('type', 'product');

        if ($typed->isNotEmpty()) {
            $categories = $typed;
        }

        return $categories
            ->take(self::MAX_COURSES)
            ->map(fn (Category $category) => [
                'title' => $this->translate($category->title, $locale),
                'url'   => $category->store_url,
                'count' => (int) ($category->products_count ?? 0),
            ])
            ->filter(fn (array $course) => $course['title'] !== '')
            ->values()
            ->all();
    }

    /**
     * The mini-list: the newest dishes on the menu, minus the one being viewed.
     *
     * @return array<int, array{title: string, url: string, price: string, image: ?string}>
     */
    protected function dishes(string $locale, int $currentId, string $symbol, string $position): array
    {
        try {
            $dishes = $this->productRepo
                ->baseIndexQuery(['status' => 'active'])
                ->when($currentId > 0, fn ($query) => $query->whereKeyNot($currentId))
                ->orderByDesc('products.created_at')
                ->orderByDesc('products.id')
                ->limit(self::MAX_DISHES)
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return $dishes
            ->map(fn (Product $dish) => [
                'title' => $this->translate($dish->title, $locale),
                'url'   => $dish->store_url,
                'price' => $this->money((float) ($dish->price ?? 0), $symbol, $position),
                'image' => $this->thumbnail($dish),
            ])
            ->filter(fn (array $dish) => $dish['title'] !== '')
            ->values()
            ->all();
    }

    /**
     * The dish's featured photograph, or its first, or nothing.
     *
     * `baseIndexQuery` eager-loads `assets` unordered, so the featured flag is applied here on
     * the loaded collection rather than with a second query per dish.
     */
    protected function thumbnail(Product $dish): ?string
    {
        $asset = $dish->assets
            ->where('usage', 'PRODUCT_IMAGE')
            ->sortByDesc('featured')
            ->first();

        if (! $asset) {
            return null;
        }

        return Str::startsWith($asset->path, 'http')
            ? $asset->path
            : '/storage/' . ltrim($asset->path, '/');
    }

    protected function money(float $amount, string $symbol, string $position): string
    {
        $formatted = number_format($amount, 2);

        return $position === 'suffix' ? $formatted . $symbol : $symbol . $formatted;
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return trim((string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : '')));
        }

        return trim((string) $value);
    }
}
