<?php

namespace Theme\Components;

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\ThemeSettings;

/**
 * The "Home › Categories › Burgers › Charcoal Chicken" trail above a page, and the
 * matching schema.org `BreadcrumbList` beside it (audit B6, pairs with B1).
 *
 * The trail is derived from the record ThemeController resolved for the page — shared as
 * `page` — so a template asks for the component and passes nothing:
 *
 *   dish        → Home › [Categories page] › first category › dish
 *   category    → Home › [Categories page | Blogs page] › category
 *   post        → Home › [Blogs page] › post
 *   tag listing → Home › [the listing page] › tag
 *   plain page  → Home › page          (nothing on the home page itself)
 *
 * The listing crumbs are the shop's own CATEGORIES and BLOGS pages, looked up once per
 * request and titled however the operator titled them — a restaurant that renames
 * "Categories" to "Menu" sees "Menu" here with no setting to keep in step. A page type
 * that does not exist in the install is simply skipped, never linked to a 404.
 *
 * Every label the trail prints is a link except the last, which is the page itself.
 */
class Breadcrumbs
{
    /** @var array<string, Page|null> */
    protected static array $listingPages = [];

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = ThemeSettings::all();

        if (! filter_var($settings['breadcrumbs_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        $page = $data['page'] ?? View::shared('page');
        $tag  = View::shared('currentTag');

        $trail = $this->trail($page, $tag instanceof Tag ? $tag : null, $locale);

        // A trail of one is the home page — nothing to show a visitor who is already there.
        if (count($trail) < 2) {
            return '';
        }

        return View::make($themeViewPath, [
            'trail'  => $trail,
            'jsonLd' => filter_var($settings['structured_data_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)
                ? $this->jsonLd($trail)
                : '',
            'locale' => $locale,
        ])->render();
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    protected function trail(mixed $page, ?Tag $tag, string $locale): array
    {
        $trail = [['label' => __('Home'), 'url' => url('/')]];

        if ($page instanceof Product) {
            $this->pushListing($trail, 'CATEGORIES', $locale);

            // The same filter on both paths. The eager-loaded branch used to take whatever came
            // first — and the dish sheet eager-loads `categories` unfiltered, so on every dish
            // page (the common case, not the fallback) a drafted category could win and the
            // trail linked to a page that 404s. A crumb is a promise that the link works.
            $category = $page->relationLoaded('categories')
                ? $page->categories
                    ->where('status', 'active')
                    ->sortBy('orders')
                    ->first()
                : $page->categories()->where('status', 'active')->orderBy('orders')->first();

            if ($category) {
                $trail[] = ['label' => $this->translate($category->title, $locale), 'url' => url($category->store_url)];
            }

            $trail[] = ['label' => $this->translate($page->title, $locale), 'url' => url($page->store_url)];

            return $trail;
        }

        if ($page instanceof Category) {
            $this->pushListing($trail, $page->type === 'blog_category' ? 'BLOGS' : 'CATEGORIES', $locale);
            $trail[] = ['label' => $this->translate($page->title, $locale), 'url' => url($page->store_url)];

            return $trail;
        }

        if ($page instanceof Post) {
            $this->pushListing($trail, 'BLOGS', $locale);
            $slug = $page->getTranslation('slug', $locale, false) ?: $page->getTranslation('slug', 'en', false);
            $trail[] = ['label' => $this->translate($page->title, $locale), 'url' => url('/blogs/' . ltrim((string) $slug, '/'))];

            return $trail;
        }

        if ($page instanceof Page) {
            if (strtoupper((string) $page->type) === 'HOME') {
                return $trail;
            }

            $trail[] = ['label' => $this->translate($page->title, $locale), 'url' => url($page->store_url)];

            if ($tag) {
                $tagSlug = $tag->getTranslation('slug', $locale, false) ?: $tag->getTranslation('slug', 'en', false);
                $trail[] = [
                    'label' => $this->translate($tag->title, $locale),
                    'url'   => url($page->store_url . '?tag=' . urlencode((string) $tagSlug)),
                ];
            }

            return $trail;
        }

        return $trail;
    }

    /**
     * Append the shop's listing page of the given type, when the install has one.
     */
    protected function pushListing(array &$trail, string $type, string $locale): void
    {
        if (! array_key_exists($type, static::$listingPages)) {
            try {
                static::$listingPages[$type] = Page::where('type', $type)->where('status', 'active')->first();
            } catch (\Throwable $e) {
                report($e);
                static::$listingPages[$type] = null;
            }
        }

        $listing = static::$listingPages[$type];

        if ($listing) {
            $trail[] = ['label' => $this->translate($listing->title, $locale), 'url' => url($listing->store_url)];
        }
    }

    protected function jsonLd(array $trail): string
    {
        $items = [];

        foreach ($trail as $index => $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'name'     => $crumb['label'],
                'item'     => $crumb['url'],
            ];
        }

        return (string) json_encode(
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return Str::of((string) ($value ?? ''))->trim()->toString();
    }
}
