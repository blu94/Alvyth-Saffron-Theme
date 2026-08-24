<?php

namespace Theme\Sections\Commerce;

use Theme\Sections\General\DishSheet;

/**
 * Claims core's **Product Details** section for the dish sheet.
 *
 * `/products/{slug}` renders the builder rows saved on the Product, and the block an operator
 * reaches for there is core's "Product Details" (`PRODUCT_DETAILS_SECTION`, group `commerce`).
 * Core's own renderer is a generic commerce layout — gallery, options matrix, add to cart. It
 * is correct for a catalogue and wrong for a menu: it knows nothing about modifier groups, so
 * a dish with "Choose your side" and "Add extras" renders with those questions missing and
 * the customer orders an unconfigured dish.
 *
 * `section.blade.php` checks the **theme** class before core's, so shipping this file makes
 * every existing product page render {@see DishSheet} instead — with no data edit, no page
 * re-authoring, and no change to what an operator places. Switch the theme off and core's
 * generic version takes over again.
 *
 * **There is no sibling `index.blade.php`, deliberately.** A driver normally pairs with its
 * own template, but this one owns no markup: it renders DishSheet's view. An empty template
 * beside it would be a file that can never be reached.
 *
 * **`$data` is handed over whole, and that is now load-bearing.** Claiming the renderer while
 * ignoring the schema is what made this block lie for as long as it has existed: core's panel
 * offers *Product Layout Variant* and two sidebar switches, a theme cannot replace core's
 * schema (`SectionController` namespaces every theme schema under `_THEME_SECTION`, so a
 * Saffron `ProductDetails.json` would be a *different* block, not an override), and Saffron
 * read none of the keys. An operator picked Layout 03, saved, reloaded, and got back exactly
 * the page they started with — no error, no hint, nothing.
 *
 * The earlier reasoning here was that a value set for a fashion grid should not silently
 * reconfigure a menu. That is true of the *wording* and false of the *layout*: where the photo
 * sits, how wide the page runs, and whether a rail of courses appears are as meaningful on a
 * dish page as on a catalogue page. So {@see DishSheet} now reads `layout_style`,
 * `sidebar_show_categories`, `sidebar_show_featured` and `style.section.*` under core's own key
 * names — one vocabulary, no translation layer to drift — while keys core's panel does not
 * offer (`size_key`, `notes_key`, `max_quantity`, `notes_max`) keep DishSheet's defaults. A
 * dish page that needs those gets a **Dish Sheet** block, whose schema carries the layout
 * control too.
 */
class ProductDetails
{
    /**
     * The view DishSheet renders. Named here rather than passed through, because the path
     * this driver receives points at *this* section's template, which does not exist.
     */
    private const DISH_SHEET_VIEW = 'partials.sections.general.DishSheet.index';

    /**
     * DishSheet is loaded here, not injected through the constructor.
     *
     * `section.blade.php` `require_once`s **only the driver file for the section being
     * rendered**, and the theme autoloader in `AppServiceProvider` maps `Theme\…` onto a
     * different path shape than `partials/sections/{group}/{Name}/index.php`. So while this
     * section is rendering, `Theme\Sections\General\DishSheet` is not loaded by anything.
     * Declaring it as a constructor dependency would fail inside `app()->make()` — before
     * `render()` ever runs — with a class-not-found that names neither section.
     */
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        if (! class_exists(DishSheet::class, false)) {
            $driver = __DIR__ . '/../../general/DishSheet/index.php';

            if (! is_file($driver)) {
                // A half-deployed theme. Returning nothing keeps the dish page serving its
                // other sections rather than throwing; the missing file is the real fault
                // and is visible as an absent block.
                return '';
            }

            require_once $driver;
        }

        return app(DishSheet::class)->render($data ?? [], $locale, self::DISH_SHEET_VIEW);
    }
}
