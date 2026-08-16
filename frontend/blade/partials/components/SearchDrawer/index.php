<?php

namespace Theme\Components;

use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;

/**
 * The menu search panel behind the header's magnifier.
 *
 * **The button used to be a link to `/collections`.** It carried a magnifier icon and an
 * aria-label saying "Search the menu", and it navigated to the category index — a control
 * that looks like search, is announced as search, and cannot search. Ella has shipped a real
 * live-search drawer the whole time (`partials/components/SearchDrawer/`), reading the same
 * endpoint this does.
 *
 * Results come from `GET /api/storefront/search?q=` (min 2 characters, 8 results), which
 * matches on title, SKU and tags and returns pre-formatted prices — so the panel never
 * re-implements currency formatting and can never disagree with the rest of the storefront
 * about a price.
 *
 * Kept deliberately smaller than Ella's: no trending keywords, no promo tiles, no popular-
 * products grid. A menu is a short list a diner scans, not a catalogue they mine, and every
 * one of those extras is a setting a restaurant operator would have to fill in before the
 * panel stopped looking broken. What is here is the search itself and, before typing, the
 * shop's menu sections as one-tap shortcuts.
 */
class SearchDrawer
{
    public function __construct(
        protected ApplicationInterface $appSettingsRepo
    ) {
    }

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = $data['settings'] ?? [];

        return View::make($themeViewPath, [
            'locale'       => $locale,
            'placeholder'  => $this->translate($settings['header_search_placeholder'] ?? null, $locale)
                ?: __('Search the menu'),
            'sections'     => $this->sections($locale),
            'browseAllUrl' => $this->browseAllUrl(),
            'labels'       => [
                // Every string the Vue app can show. Held here rather than in the script so a
                // Malay menu does not surface English error text — the mistake this theme
                // already made once in the dish sheet.
                'searching' => __('Searching…'),
                'noResults' => __('Nothing on the menu matches'),
                'browseAll' => __('Browse the whole menu'),
                'results'   => __('Results'),
            ],
        ])->render();
    }

    /**
     * The menu sections offered as shortcuts before anything is typed.
     *
     * Derived from categories that actually contain dishes — never from the category `type`
     * column, which the form validates as `product|page` but which imported catalogues fill
     * with anything. A shortcut rail that empties itself because an importer wrote `general`
     * is the failure this avoids.
     *
     * @return array<int,array{title:string,url:string}>
     */
    protected function sections(string $locale): array
    {
        return \App\Models\Category::query()
            ->where('status', 'active')
            ->has('products')
            ->orderBy('orders')
            ->limit(8)
            ->get(['id', 'title', 'slug'])
            ->map(fn ($category) => [
                'title' => $this->translate($category->title, $locale),
                'url'   => $category->store_url,
            ])
            ->filter(fn ($row) => $row['title'] !== '')
            ->values()
            ->all();
    }

    /**
     * Where "Browse the whole menu" goes when a search finds nothing, or nothing has been typed.
     *
     * The shop's own CATEGORIES page — the same record the breadcrumb trail resolves — rather
     * than a hard-coded `/collections`, because page slugs are translatable and an operator may
     * have renamed it. Null when the shop has no such page, in which case the panel simply
     * shows no way out rather than a link to a 404; the label was being built for this button
     * for the whole life of the drawer and never rendered (audit A20).
     */
    protected function browseAllUrl(): ?string
    {
        $listing = \App\Models\Page::query()
            ->where('type', 'CATEGORIES')
            ->where('status', 'active')
            ->first();

        return $listing ? url($listing->store_url) : null;
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || $value === []) {
            return '';
        }

        return (string) ($value[$locale] ?? $value[config('app.fallback_locale', 'en')] ?? reset($value));
    }
}
