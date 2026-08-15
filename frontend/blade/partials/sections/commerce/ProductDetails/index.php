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
 * The two schemas do not share keys — core's block carries `layout_style` and sidebar
 * toggles, DishSheet reads `size_key`, `notes_key`, `max_quantity`. Nothing is translated
 * between them: DishSheet falls back to its own defaults, which is the right answer, because
 * a value an operator set for a fashion-grid layout should not silently reconfigure a menu.
 * A dish page that needs those controls gets a **Dish Sheet** block placed explicitly.
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
