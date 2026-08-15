<?php

namespace Theme\Sections\General;

use App\Repositories\Category\CategoryInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\Motion;

/**
 * The full menu, grouped into sections.
 *
 * A menu section is a core Category with `type = product`; a dish is a Product attached
 * to it. The spec models no new tables for either — ordering and visibility are edited in
 * the core Categories and Products screens, and this driver only reads them.
 *
 * Section schemas have no mechanism for fetching options from the database, so there is no
 * category picker to offer. Curation is therefore either the categories' own `orders` and
 * `status` columns, or the `only_slugs` allow-list on this section.
 *
 * Lives under the `general` group, not `commerce`, because SectionRegistry::getSectionMap()
 * indexes only core section schemas — a theme-introduced type has no core counterpart and
 * so falls back to `general`. The driver has to sit where the builder will look for it.
 */
class MenuSections
{
    public function __construct(
        protected CategoryInterface $categoryRepo
    ) {}

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $heading    = $this->translate($data['heading'] ?? '', $locale);
        $subheading = $this->translate($data['subheading'] ?? '', $locale);

        $layout           = $data['layout'] ?? 'grid';
        $colsDesktop      = (string) ($data['columns_desktop'] ?? '3');
        $limitPerSection  = (int) ($data['limit_per_section'] ?? 0);
        $showSectionNav   = $data['show_section_nav'] ?? true;
        $showSectionCount = $data['show_section_count'] ?? false;
        $hideEmpty        = $data['hide_empty_sections'] ?? true;

        $onlySlugs = collect(explode(',', (string) ($data['only_slugs'] ?? '')))
            ->map(fn ($slug) => Str::slug(trim($slug)))
            ->filter()
            ->values();

        // One query for the categories plus a nested eager load of their dishes. Dishes are
        // constrained to `status = active` here rather than in the loop, so a sold-out item
        // is absent from the menu instead of rendered and then hidden.
        //
        // A menu section is defined by containing dishes, not by its `type` column alone.
        // The spec calls it a Category with `type = product`, and the Categories admin only
        // offers Product or Page — but seeded and older data carries `general`, and a
        // category holding twenty active dishes is a menu section whatever it is labelled.
        // So the SQL requires dishes, and the type is applied afterwards as a preference:
        // when any properly typed category exists, only those are shown. This keeps a
        // correctly configured shop exact without leaving a legacy one with a blank menu.
        $categories = $this->categoryRepo
            ->baseIndexQuery(['status' => 'active'])
            ->whereHas('products', function ($query) {
                $query->where('products.status', 'active')
                    ->whereNull('products.productable_id');
            })
            ->with([
                'products' => function ($query) {
                    $query->where('products.status', 'active')
                        ->whereNull('products.productable_id')
                        ->with(['variants', 'tags', 'assets'])
                        ->orderBy('products.orders')
                        ->orderBy('products.id');
                },
            ])
            ->orderBy('orders')
            ->orderBy('id')
            ->get();

        $typed = $categories->where('type', 'product');
        $usedTypeFilter = $typed->isNotEmpty();

        if ($usedTypeFilter) {
            $categories = $typed;
        }

        $sections = $categories
            ->when($onlySlugs->isNotEmpty(), function ($collection) use ($onlySlugs, $locale) {
                return $collection->filter(function ($category) use ($onlySlugs, $locale) {
                    return $onlySlugs->contains($this->categorySlug($category, $locale));
                });
            })
            ->map(function ($category) use ($locale, $limitPerSection) {
                $dishes = $category->products;
                $total  = $dishes->count();

                if ($limitPerSection > 0) {
                    $dishes = $dishes->take($limitPerSection);
                }

                $slug = $this->categorySlug($category, $locale);

                return [
                    'id'        => $category->id,
                    'anchor'    => 'menu-' . ($slug !== '' ? $slug : $category->id),
                    'title'     => $this->translate($category->title, $locale),
                    'desc'      => trim(strip_tags((string) $this->translate($category->description, $locale))),
                    'url'       => $category->store_url,
                    'dishes'    => $dishes,
                    'shown'     => $dishes->count(),
                    'total'     => $total,
                    'hasMore'   => $limitPerSection > 0 && $total > $limitPerSection,
                ];
            })
            ->when($hideEmpty, fn ($collection) => $collection->filter(fn ($section) => $section['shown'] > 0))
            ->values();

        return View::make($themeViewPath, [
            'heading'          => $heading,
            'subheading'       => $subheading,
            'sections'         => $sections,
            'layout'           => $layout,
            'gridColClass'     => $this->gridColClass($colsDesktop),
            'showSectionNav'   => $showSectionNav && $sections->count() > 1,
            'showSectionCount' => $showSectionCount,
            'usedTypeFilter'   => $usedTypeFilter,
            'stickyNav'        => $data['menu_sticky_nav'] ?? true,
            'cardSettings'     => [
                'layout'           => $layout,
                'show_price'       => $data['show_price'] ?? true,
                'show_tags'        => $data['show_tags'] ?? true,
                'show_description' => $data['show_description'] ?? true,
                'show_add_button'  => $data['show_add_button'] ?? true,
                'add_button_label' => $data['add_button_label'] ?? 'Add',
            ],
            'ratioClass'       => $this->ratioClass($data['image_ratio'] ?? ''),
            'motionAttrs'      => Motion::sectionAttributes($data),
            'data'             => $data,
            'locale'           => $locale,
        ])->render();
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }

    protected function categorySlug(mixed $category, string $locale): string
    {
        return (string) ($category->getTranslation('slug', $locale, false)
            ?: $category->getTranslation('slug', 'en', false));
    }

    /**
     * Two columns on a phone whatever the desktop count — a one-column menu wastes half
     * the screen, and a four-column one is unreadable at 375px.
     */
    protected function gridColClass(string $colsDesktop): string
    {
        return match ($colsDesktop) {
            '2' => 'col-12 col-sm-6 col-lg-6',
            '4' => 'col-6 col-md-4 col-lg-3',
            default => 'col-6 col-md-6 col-lg-4',
        };
    }

    /**
     * Blade may not carry an inline style, and the ratio is a closed enum in the schema,
     * so the override travels as a class defined once in the dish-card stylesheet. An
     * empty value means "inherit the theme setting" and adds no class at all.
     */
    protected function ratioClass(mixed $ratio): string
    {
        return match (trim((string) $ratio)) {
            '1/1'  => 'saffron-ratio-1-1',
            '4/3'  => 'saffron-ratio-4-3',
            '16/9' => 'saffron-ratio-16-9',
            '3/4'  => 'saffron-ratio-3-4',
            default => '',
        };
    }
}
