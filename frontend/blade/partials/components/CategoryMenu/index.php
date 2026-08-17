<?php

namespace Theme\Components;

use App\Models\Category;
use Illuminate\Support\Facades\View;

/**
 * The dishes in one category, for a category page nobody has laid out by hand.
 *
 * **The problem.** `/collections/{slug}` renders the Category's builder rows, and a freshly
 * installed shop has none on any category — so every category page printed *"This part of the
 * menu is being updated"* until an operator opened each one and dropped a Menu Sections block
 * on it individually. The breadcrumb on every dish page links there, and so does the header's
 * Menu dropdown, which made the theme's most-reached secondary screen a dead end on a shop that
 * had done nothing wrong (register E6).
 *
 * **Why it was left that way, and why that reasoning was half right.** The template said:
 * *"There is deliberately no query fallback here: a page template has no driver hook, and
 * fetching dishes from Blade would put data logic in the presentation layer."* The principle
 * holds — the conclusion does not. A page template reaches a driver through
 * `<x-theme.component>`, which that same template was already using for `Breadcrumbs`. This is
 * that hook, so the data logic lands in a driver exactly as the rule wants.
 *
 * **It renders the block an operator would have added**, rather than a second implementation of
 * it: the Menu Sections section, scoped to this category's own slug. That is the whole design.
 * A category page and a hand-built one draw through the same code, so they cannot drift, and an
 * operator who *does* build a custom layout still overrides this — the template prefers rows
 * whenever the category has any.
 *
 * **It owns the empty state as well as the full one.** The page template used to print
 * *"being updated"* in its `@else` branch, which meant the message and the dishes were decided
 * in two places — and the template cannot know whether this driver found anything without
 * capturing its output, which is the data logic the rule exists to prevent. So both outcomes
 * come from here: the dishes, or the panel with a way back to the menu.
 */
class CategoryMenu
{
    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $page = $data['page'] ?? View::shared('page');

        if (! $page instanceof Category) {
            return '';
        }

        $title = $this->translate($page->title ?? '', $locale);
        $lede  = trim(strip_tags($this->translate($page->description ?? '', $locale)));
        $slug  = $this->slug($page, $locale);

        $dishes = $slug !== '' && $this->hasDishes($page)
            ? $this->menuFor($slug, $locale)
            : '';

        return View::make($themeViewPath, [
            'title'  => $title,
            'lede'   => $lede,
            // Already-rendered HTML from the Menu Sections driver, or '' when this category
            // has nothing to list. The view prints one or the other; it decides nothing.
            'menu'   => $dishes,
            'homeUrl' => url('/'),
        ])->render();
    }

    /** The Menu Sections block an operator would have added, scoped to this one category. */
    protected function menuFor(string $slug, string $locale): string
    {
        $section = $this->menuSections();

        if ($section === null) {
            return '';
        }

        return $section->render([
            // The section's own allow-list, which is how an operator scopes one by hand.
            'only_slugs'          => $slug,
            // One section needs no jump-links to itself.
            'show_section_nav'    => false,
            // The view prints the category's name as its <h1>; the section would otherwise
            // print it again as an <h3> directly underneath.
            'show_section_titles' => false,
        ], $locale, 'partials.sections.general.MenuSections.index');
    }

    /**
     * The Menu Sections driver, or null if it cannot be loaded.
     *
     * Required by path rather than autoloaded, and that is not a shortcut: the theme
     * autoloader maps `Theme\Sections\General\MenuSections` to `sections/General/MenuSections.php`,
     * while section drivers actually live at
     * `frontend/blade/partials/sections/general/MenuSections/index.php` — they are resolved by
     * path from core's builder, never by namespace. Core's own `section.blade.php` and
     * `Theme\Component` both `require_once` them the same way.
     *
     * Made through the container because the driver constructor-injects `CategoryInterface`.
     */
    protected function menuSections(): ?object
    {
        $class = 'Theme\\Sections\\General\\MenuSections';

        if (! class_exists($class, false)) {
            $file = dirname(__DIR__, 2) . '/sections/general/MenuSections/index.php';

            if (! is_file($file)) {
                return null;
            }

            require_once $file;
        }

        return class_exists($class) ? app()->make($class) : null;
    }

    /**
     * Whether this category has anything a menu would list.
     *
     * The same two conditions Menu Sections queries on — published, and a parent product
     * rather than a variant. A variant is a Product too (it carries `productable_id`), so
     * without that second clause a category holding one dish in three sizes would count four.
     */
    protected function hasDishes(Category $category): bool
    {
        return $category->products()
            ->where('products.status', 'active')
            ->whereNull('products.productable_id')
            ->exists();
    }

    /**
     * Resolved the way Menu Sections resolves it, falling back to English — the section
     * matches on the slug, so the two have to agree or the allow-list filters everything out.
     */
    protected function slug(Category $category, string $locale): string
    {
        return (string) ($category->getTranslation('slug', $locale, false)
            ?: $category->getTranslation('slug', 'en', false));
    }

    /** A translatable column arrives as a locale-keyed array, or as a plain string on old rows. */
    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
