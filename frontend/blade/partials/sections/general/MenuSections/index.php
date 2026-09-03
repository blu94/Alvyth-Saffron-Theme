<?php

namespace Theme\Sections\General;

use App\Repositories\Category\CategoryInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\Motion;
use Theme\Backend\Support\SectionSetting;
use Theme\Backend\Support\ThemeSettings;

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

        // The section's Status control — Disabled renders nothing (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        // Theme-wide defaults from the Restaurant settings tab. Every presentation control
        // below resolves section → theme → built-in, so "Theme default" on the section is
        // a real choice and the tab's switches actually change the menu (audit A8).
        $settings = ThemeSettings::all();

        $heading    = $this->translate($data['heading'] ?? '', $locale);
        $subheading = $this->translate($data['subheading'] ?? '', $locale);

        $layout           = SectionSetting::choice($data['layout'] ?? '', $settings['menu_layout'] ?? '', 'grid');
        $colsDesktop      = SectionSetting::choice($data['columns_desktop'] ?? '', $settings['menu_columns'] ?? '', '3');
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
            // **Both closures, and they are not the same job.** The first decides whether the
            // section appears at all — a category this branch serves nothing from is not a menu
            // section for this customer, it is an empty heading. The second decides which dishes
            // it holds. Constraining only the second would keep drawing the heading with nothing
            // under it, which is exactly what a category page did before this.
            ->whereHas('products', function ($query) {
                BranchScope::constrain(
                    $query->where('products.status', 'active')
                        ->whereNull('products.productable_id')
                );
            })
            ->with([
                'products' => function ($query) {
                    BranchScope::constrain(
                        $query->where('products.status', 'active')
                            ->whereNull('products.productable_id')
                    )
                        ->with(['variants', 'tags', 'assets'])
                        ->orderBy('products.orders')
                        ->orderBy('products.id');
                },
            ])
            ->orderBy('orders')
            ->orderBy('id')
            ->get();

        // The type preference is a guess about which categories are menu sections, and an
        // explicit allow-list is not a guess — so naming slugs turns it off. Without this, a
        // block scoped to a category carrying the legacy `general` type rendered **nothing**
        // on any shop that also had one properly typed category: the preference dropped it
        // before the allow-list was ever consulted, and the section printed its empty panel
        // with no hint that the slug had matched a real category. Measured on a seeded shop —
        // `dummy-lifestyle` holds six dishes and drew an empty menu.
        $typed  = $categories->where('type', 'product');
        $scoped = $onlySlugs->isNotEmpty();

        if (! $scoped && $typed->isNotEmpty()) {
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
            // Drives a debug-only notice telling the operator their categories are untyped.
            // A block scoped to named slugs suppresses it: the type preference was not
            // consulted for that block, so the advice would be about a decision it did not
            // make — and it appeared mid-page on every category page until this was split
            // out of the filtering flag it used to share.
            'usedTypeFilter'   => $scoped || $typed->isNotEmpty(),
            // Not a schema control, and deliberately not one: the only caller that turns it
            // off is the category page, which renders this section scoped to a single
            // category and prints that category's name as its own <h1>. Without it the name
            // appears twice, once as the page heading and once as the section's <h3>. An
            // operator dropping a Menu Sections block on a page always wants the titles, so
            // offering a switch would be a control with one right answer.
            // Internal, and deliberately not a schema field: `CategoryMenu` renders this same
            // section with the titles off, because it draws its own. An operator placing the
            // block always wants them on, so a control here would only offer a way to break
            // the menu. Documented rather than exposed — it read as a missing setting.
            'showSectionTitles' => $data['show_section_titles'] ?? true,
            // A theme setting, not a section key: the section schema never declared
            // `menu_sticky_nav`, so reading it from $data made the toggle permanently on.
            'stickyNav'        => SectionSetting::bool(null, $settings['menu_sticky_nav'] ?? null, true),
            'cardSettings'     => [
                'layout'           => $layout,
                'show_price'       => SectionSetting::bool($data['show_price'] ?? null, $settings['dish_show_price'] ?? null, true),
                'show_tags'        => SectionSetting::bool($data['show_tags'] ?? null, $settings['dish_show_tags'] ?? null, true),
                'show_description' => SectionSetting::bool($data['show_description'] ?? null, $settings['dish_show_description'] ?? null, true),
                'show_add_button'  => $data['show_add_button'] ?? true,
                'add_button_label' => $data['add_button_label'] ?? 'Add',
                // The card's sold-out badge wording. Comes from the theme's Restaurant tab
                // and was never passed through, so the operator's phrase never reached a
                // card (audit A7).
                'sold_out_label'   => $settings['sold_out_label'] ?? '',
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
