<?php

namespace Theme\Sections\General;

use App\Models\Product;
use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Models\ModifierGroup;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\ThemeSettings;

/**
 * The configure-and-add block — the single most-used screen in the theme.
 *
 * Composes, in order: size (from product variants), the dish's modifier groups as radios or
 * checkboxes honouring min/max, special instructions, quantity, and a live running total.
 *
 * Everything the customer picks becomes ONE FLAT, STRING-VALUED options object on the cart
 * line:
 *
 *     { Size: 'Large', Side: 'Fries', Extras: 'Cheese, Bacon', Notes: 'No onions' }
 *
 * Flat and string-valued on purpose: that is exactly what `validateCart` normalises, ksorts
 * and dedups, and what lands in `OrderItem.meta['options']` for the kitchen to read.
 *
 * The section resolves its dish from the page being rendered, so dropping it on a Product
 * page needs no configuration. `data.product_id` overrides that for a landing page pinned to
 * one dish.
 */
class DishSheet
{
    /** Per-request render counter — deterministic uids, so ETag revalidation can match. */
    private static int $uidSequence = 0;

    /**
     * Tabler glyph bodies for the share panel, keyed by the network's settings key.
     *
     * Inlined because this theme ships no icon font — Ella writes `<i class="ti ti-brand-…">`
     * and can, because its layout loads Tabler's webfont; Saffron's does not, and pulling one
     * in for 28 glyphs would cost every page a font request for a panel most visitors never
     * open. These strings are a constant declared here and never touched by user input, which
     * is why the view is allowed to echo them raw.
     */
    private const SHARE_ICONS = [
        'facebook' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 10v4h3v7h4v-7h3l1-4h-4V8a1 1 0 0 1 1-1h3V3h-3a5 5 0 0 0-5 5v2z"/>',
        'twitter' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m4 4l11.733 16H20L8.267 4zm0 16l6.768-6.768m2.46-2.46L20 4"/>',
        'linkedin' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M8 11v5m0-8v.01M12 16v-5m4 5v-3a2 2 0 1 0-4 0"/><path d="M3 7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4z"/></g>',
        'whatsapp' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="m3 21l1.65-3.8a9 9 0 1 1 3.4 2.9z"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0zm0 0a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/></g>',
        'pinterest' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="m8 20l4-9m-1.3 3c.437 1.263 1.43 2 2.55 2c2.071 0 3.75-1.554 3.75-4a5 5 0 1 0-9.7 1.7"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0-18 0"/></g>',
        'telegram' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 10l-4 4l6 6l4-16l-18 7l4 2l2 6l3-4"/>',
        'reddit' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M12 8c2.648 0 5.028.826 6.675 2.14a2.5 2.5 0 0 1 2.326 4.36c0 3.59-4.03 6.5-9 6.5c-4.875 0-8.845-2.8-9-6.294l-1-.206a2.5 2.5 0 0 1 2.326-4.36C5.973 8.827 8.353 8 11.001 8zm0 0l1-5l6 1"/><path d="M18 4a1 1 0 1 0 2 0a1 1 0 1 0-2 0"/><circle cx="9" cy="13" r=".5" fill="currentColor"/><circle cx="15" cy="13" r=".5" fill="currentColor"/><path d="M10 17q1 .5 2 .5c1 0 1.333-.167 2-.5"/></g>',
        'email' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="m3 7l9 6l9-6"/></g>',
        'tumblr' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 21h4v-4h-4v-6h4V7h-4V3h-4v1a3 3 0 0 1-3 3H6v4h4v6a4 4 0 0 0 4 4"/>',
        'vk' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 19h-4a8 8 0 0 1-8-8V6h4v5a4 4 0 0 0 4 4V6h4v4.5h.03A4.53 4.53 0 0 0 18 6.004h4l-.342 1.711A6.86 6.86 0 0 1 18 12.504a5.34 5.34 0 0 1 3.566 4.111L22 19.004h-4a4.53 4.53 0 0 0-3.97-4.496v4.5z"/>',
        'xing' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m16 21l-4-7l6.5-11M7 7l2 3.5L6 15"/>',
        'line' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10.663C21 6.439 16.959 3 12 3s-9 3.439-9 7.663c0 3.783 3.201 6.958 7.527 7.56c1.053.239.932.644.696 2.133c-.039.238-.184.932.777.512c.96-.42 5.18-3.201 7.073-5.48C20.377 13.884 21 12.359 21 10.673z"/>',
        'viber' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2m10 3a2 2 0 0 1 2 2m-2-6a6 6 0 0 1 6 6"/>',
        'skype' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M12 3a9 9 0 0 1 8.603 11.65a4.5 4.5 0 0 1-5.953 5.953A9 9 0 0 1 3.397 9.35A4.5 4.5 0 0 1 9.35 3.396A9 9 0 0 1 12 3"/><path d="M8 14.5c.5 2 2.358 2.5 4 2.5c2.905 0 4-1.187 4-2.5c0-1.503-1.927-2.5-4-2.5s-4-1-4-2.5C8 8.187 9.095 7 12 7c1.642 0 3.5.5 4 2.5"/></g>',
        'weibo' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14.127C19 17.2 15.498 20 11 20c-4.126 0-8-2.224-8-5.565c0-1.78.984-3.737 2.7-5.567c2.362-2.51 5.193-3.687 6.551-2.238c.415.44.752 1.39.749 2.062c2-1.615 4.308.387 3.5 2.693c1.26.557 2.5.538 2.5 2.742M15 4h1a5 5 0 0 1 5 5v1"/>',
        'hackernews' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="m8 7l4 6l4-6m-4 10v-4"/></g>',
        'pocket' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M5 4h14a2 2 0 0 1 2 2v6a9 9 0 0 1-18 0V6a2 2 0 0 1 2-2"/><path d="m8 11l4 4l4-4"/></g>',
        'flipboard' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 7v14l-6-4l-6 4V7a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4"/>',
        'instapaper' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 7v14l-6-4l-6 4V7a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4"/>',
        'evernote' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M4 8h5V3"/><path d="M17.9 19c.6-2.5 1.1-5.471 1.1-9c0-4.5-2-5-3-5c-1.906 0-3-.5-3.5-1c-.354-.354-.5-1-1.5-1H9L4 8c0 6 2.5 8 5 8c1 0 1.5-.5 2-1.5s1.414-.326 2.5 0c1.044.313 2.01.255 2.5.5c1 .5 2 1.5 2 3c0 .5 0 3-3 3s-3-3-1-3m1-8h1"/></g>',
        'trello' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M7 7h3v10H7zm7 0h3v6h-3z"/></g>',
        'mix' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zm0 10a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zm10 0a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1zm0-8h6m-3-3v6"/>',
        'digg' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 6h3a1 1 0 0 1 1 1v11a2 2 0 0 1-4 0V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v12a3 3 0 0 0 3 3h11M8 8h4m-4 4h4m-4 4h4"/>',
        'blogger' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M8 21h8a5 5 0 0 0 5-5v-3a3 3 0 0 0-3-3h-1V8a5 5 0 0 0-5-5H8a5 5 0 0 0-5 5v8a5 5 0 0 0 5 5"/><path d="M7 8.5A1.5 1.5 0 0 1 8.5 7h3A1.5 1.5 0 0 1 13 8.5a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 7 8.5m0 7A1.5 1.5 0 0 1 8.5 14h7a1.5 1.5 0 0 1 1.5 1.5a1.5 1.5 0 0 1-1.5 1.5h-7A1.5 1.5 0 0 1 7 15.5"/></g>',
        'sms' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9h8m-8 4h6m4-9a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3h-5l-5 3v-3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3z"/>',
        'threads' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7.5Q17 3 12 3c-5 0-8 2.5-8 9s3.5 9 8 9s7-3 7-5s-1-5-7-5c-2.5 0-3 1.25-3 2.5C9 15 10 16 11.5 16c2.5 0 3.5-1.5 3.5-5s-2-4-3-4s-1.833.333-2.5 1"/>',
        'tiktok' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 7.917v4.034A9.95 9.95 0 0 1 16 10v4.5a6.5 6.5 0 1 1-8-6.326V12.5a2.5 2.5 0 1 0 4 2V3h4.083A6.005 6.005 0 0 0 21 7.917"/>',
        'xiaohongshu' => '<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M19 4v16H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M19 16H7a2 2 0 0 0-2 2M9 8h6"/></g>',
    ];

    public function __construct(
        protected ApplicationInterface $appSettingsRepo,
        // The review aggregate for the summary beside the dish title. Ella's ProductDetails
        // injects the same repository for the same purpose.
        protected \App\Repositories\Interaction\InteractionInterface $interactionRepo,
        // Whether the store has comments switched on at all — the quick-view sheet
        // shows the same reviews the dish page does, so it answers to the same switch.
        protected \App\Services\Storefront\InteractionSettings $interactions
    ) {}

    /**
     * The star line under the dish name: average, count, and a link down to the reviews.
     *
     * Ella prints this in its product header and Saffron printed nothing — a customer deciding
     * whether to order saw no rating at all until they scrolled past the ordering form to the
     * bottom of the page (register E3, the half that was missed when the list was ported).
     *
     * **Zero reviews still renders**, as empty stars reading "No reviews yet". Hiding the line
     * until somebody has written one is what the reviews section itself was doing, and it makes
     * a shop with no reviews look like a shop with no review feature — which is the opposite of
     * an invitation to leave the first one.
     *
     * @return array{average: float|null, count: int}
     */
    protected function reviewSummary(Product $dish): array
    {
        try {
            // Stored on the product by core (item 18), so a dish sheet never loads its
            // reviews just to average them.
            $count = (int) $dish->rating_count;

            return [
                'average' => $count > 0 ? (float) $dish->rating_avg : null,
                'count'   => $count,
            ];
        } catch (\Throwable $e) {
            // A dish page must render even if the interactions table is unavailable. The
            // reviews section below fetches its own copy over HTTP and will report separately.
            report($e);

            return ['average' => null, 'count' => 0];
        }
    }

    /**
     * The eight layouts core's **Product Details** block offers, in its own vocabulary.
     *
     * The keys are core's `layout_style` values verbatim, not a Saffron dialect, because this
     * sheet is what renders when an operator places core's block — translating them would put a
     * second name on the same setting and guarantee the two drift.
     */
    private const LAYOUTS = [
        'default', 'full_width', 'grid', 'slider',
        'left_thumbs', 'right_thumbs', 'left_sidebar', 'right_sidebar',
    ];

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        // The section's Status control — Disabled renders nothing (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        // Every control the block's panel shows, resolved once for both branches below — the
        // "no dish" notice is laid out by the same rules as the sheet.
        $shape = $this->shape($data);

        // Theme-wide wording lives on the Restaurant settings tab; a section may override it.
        // The sheet used to read only its own key while the cards read the theme's, so one
        // shop could show two different "sold out" phrases for the same dish (audit A7).
        $settings     = ThemeSettings::all();
        $soldOutLabel = $this->translate($data['sold_out_label'] ?? '', $locale)
            ?: $this->translate($settings['sold_out_label'] ?? '', $locale)
            ?: __('Sold out for today');

        $dish = $this->resolveDish($data);

        if (!$dish) {
            return View::make($themeViewPath, array_merge($shape, [
                'dish'        => null,
                'servedHere'  => true,
                'branchName'  => '',
                'uid'         => 'dish-sheet-empty',
                'cssId'       => $shape['customId'] ?: 'dish-sheet-empty',
                'dishId'      => 0,
                'locale'      => $locale,
                'data'        => $data,
                'groups'      => [],
                'variants'    => [],
                'payload'     => [],
                'shareNetworks' => [],
                'heading'     => '',
                'description' => '',
                'notesLabel'  => '',
                'notesMax'    => 0,
                'showNotes'   => false,
                'isAvailable' => false,
                'soldOutLabel' => '',
                // The blade reads both unconditionally; without them this branch throws on an
                // undefined variable instead of rendering the "no dish resolved" notice.
                'categoryLinks' => [],
                'tags'          => [],
            ]))->render();
        }

        $appSettings      = $this->appSettingsRepo->getSettings();
        $currencySymbol   = $appSettings['currency_symbol'] ?? '$';
        $currencyPosition = $appSettings['currency_position'] ?? 'prefix';

        $title       = $this->translate($dish->title, $locale);
        $description = trim(strip_tags((string) $this->translate($dish->description, $locale)));

        // ── What the dish is, and where it sits on the menu ──────────────────────
        // Ella's product page prints both beside the price; Saffron printed neither, so a dish
        // page named no course and showed none of the labels its own card already carries
        // (register E4).
        //
        // Categories are links and tags are not, and that asymmetry is deliberate rather than an
        // oversight. `Category::store_url` resolves to `/collections/{slug}`, which
        // `ThemeController` matches to a real category page. A tag has no page: core answers
        // `/collections?tag={slug}` by sharing a `currentTag`, and **nothing reads it** — not
        // core's collection or product grids, not this theme's overrides of them — so the URL
        // renders the entire unfiltered menu. A chip promising "just the spicy dishes" that
        // returns all of them is worse than a chip that stays put, so tags render exactly as
        // `DishCard` already renders them: plain labels.
        $categoryLinks = ($data['show_categories'] ?? true)
            ? $dish->categories
                ->map(fn ($category) => [
                    'title' => $this->translate($category->title, $locale),
                    'url'   => $category->store_url,
                ])
                ->filter(fn (array $category) => $category['title'] !== '')
                ->values()
                ->all()
            : [];

        $tags = ($data['show_tags'] ?? true)
            ? $dish->tags
                ->map(fn ($tag) => $this->translate($tag->title, $locale))
                ->filter()
                ->values()
                ->all()
            : [];

        // ── Sizes ───────────────────────────────────────────────────────────────
        // A variant IS a Product whose productable_id points at the parent, so its own
        // translatable title is the size label. Inactive variants are dropped rather than
        // rendered disabled: a size the kitchen has withdrawn is not a choice.
        //
        // A sold-out size is different: it stays on the sheet, disabled, because the customer
        // needs to see that Large exists and is gone rather than wonder whether the shop ever
        // sold it. `stock` is null for unlimited, so only a real `0` disables anything.
        // What this branch adds to (or takes off) the dish's own price. Resolved once and
        // applied to the parent AND every size, so a Large keeps its premium over a Regular —
        // see BranchScope::priceShift(). The same number ModifierPricing adds at the cart, so
        // the sheet and the basket cannot disagree.
        $branchShift = BranchScope::priceShift((int) $dish->id, (float) ($dish->price ?? 0));

        $variants = $dish->variants
            ->where('status', 'active')
            ->map(function (Product $variant) use ($locale, $branchShift) {
                return [
                    'id'        => $variant->id,
                    'label'     => $this->variantLabel($variant, $locale),
                    'price'     => max(0.0, (float) ($variant->price ?? 0) + $branchShift),
                    'available' => $variant->stock === null || (int) $variant->stock > 0,
                ];
            })
            ->values()
            ->all();

        // ── Modifier groups ─────────────────────────────────────────────────────
        $groups = $this->resolveGroups($dish, $locale);

        // There was a debug warning here on any dish carrying a non-zero price_delta, because
        // the sheet showed a surcharge the server then did not collect. `ModifierPricing`
        // closed that gap — core asks this theme what a line's answers cost and adds it to the
        // unit price — so the running total below is now the price that is charged, and a
        // warning saying otherwise would be the lie (spec §2.3 approach C, register O1).

        // Sold out when nothing on the sheet can be bought. With sizes that means every size
        // is gone; without them it is the dish's own stock. `status` still gates publication,
        // and core refuses a zero-stock line in `validateCart`, so the button being disabled
        // here and the server refusing there are the same rule stated twice rather than two
        // rules that can drift.
        $sellable = collect($variants)->contains(fn ($variant) => $variant['available']);

        $isAvailable = ($dish->status ?? 'active') === 'active'
            && ($variants === []
                ? ($dish->stock === null || (int) $dish->stock > 0)
                : $sellable);

        // ...and whether the branch being browsed has run out of it tonight. Applied last and
        // only ever downward, exactly as `DishCard` applies it: a dish the shop can sell but this
        // kitchen cannot make is sold out *here*, and no amount of stock elsewhere changes that.
        //
        // **The card had this check and the sheet did not**, which made the menu tell the truth
        // and the page it links to contradict it: the card greyed out and wore its badge, and one
        // click later the sheet offered a live Add button for the same dish. Reachable by the
        // card's own link, by search, by a shared URL — and the refusal then arrived at the
        // payment button, which is the experience `servedHere` below exists to prevent. The two
        // halves of "can this branch make it" now sit together rather than one floor apart.
        if ($isAvailable && ! BranchScope::inStock((int) $dish->id)) {
            $isAvailable = false;
        }

        $notesMax    = (int) ($data['notes_max'] ?? 140);

        // ── Sharing ─────────────────────────────────────────────────────────────
        // Absolute, because a shared link leaves this site. `store_url` is relative, which is
        // right for an href on the page and useless in a WhatsApp message.
        $shareUrl      = url($dish->store_url);
        $shareNetworks = $this->shareNetworks($appSettings, $shareUrl, $title, $this->primaryImageUrl($dish));

        $payload = [
            'dish' => [
                'id'    => $dish->id,
                'title' => $title,
                'price' => max(0.0, (float) ($dish->price ?? 0) + $branchShift),
                'image' => $this->primaryImageUrl($dish),
                // For the saved-dishes list, which renders from localStorage alone and has
                // no other route back to this sheet.
                'url'   => $dish->store_url,
            ],
            // Every photograph, for the gallery strip and the lightbox (register E1, E2).
            'images'   => $this->galleryUrls($dish),
            'variants' => $variants,
            'groups'   => $groups,
            'currency' => [
                'symbol'   => $currencySymbol,
                'position' => $currencyPosition,
            ],
            'labels' => [
                'notesKey' => (string) ($data['notes_key'] ?? 'Notes'),
                'sizeKey'  => (string) ($data['size_key'] ?? 'Size'),
                'added'    => __('Added to your order'),
                'chooseOne' => __('Choose one'),
                'required' => __('Required'),
                'optional' => __('Optional'),
                'upTo'     => __('Choose up to :n', ['n' => ':n']),
                // The remaining strings the JS shows. Translated here, with everything else,
                // rather than hardcoded in the script — a Malay menu was showing three
                // English error lines because these lived in JS string literals.
                'zoom'             => __('View this photo full size'),
                'thumbnail'        => __('Show photo :n', ['n' => ':n']),
                'closePhoto'       => __('Close the photo'),
                'prevPhoto'        => __('Previous photo'),
                'nextPhoto'        => __('Next photo'),
                'chooseAtLeast'    => __('Choose at least :n', ['n' => ':n']),
                'answerHighlighted' => __('Please answer the highlighted questions.'),
                'cartUnavailable'  => __('The cart is unavailable. Please reload the page.'),
                'save'             => __('Save this dish'),
                'unsave'           => __('Remove from saved dishes'),
                'share'            => __('Share'),
                'shareDish'        => __('Share this dish'),
                'copyLink'         => __('Copy link'),
                'copied'           => __('Link copied'),
            ],
            'share' => [
                'url'   => $shareUrl,
                'title' => $title,
            ],
            'notesMax'  => $notesMax,
            'maxQty'    => max(1, (int) ($data['max_quantity'] ?? 20)),
            'available' => $isAvailable,
        ];

        // Deterministic, not random: a random suffix made every render byte-unique, which
        // kept the storefront's content-hash ETag from ever matching (audit P5's rule).
        $uid = 'dish-sheet-' . $dish->id . '-' . ++self::$uidSequence;

        return View::make($themeViewPath, array_merge($shape, [
            'dish'                 => $dish,
            // **Does the branch this customer is browsing actually serve this dish?**
            // (register O18a, phase 3.) Phase 2 hid the card on the menu and stopped there, so
            // this page still offered an Add button for a dish the branch cannot make — reachable
            // by link, by search, or by anyone who had the page open before switching branch. The
            // refusal at checkout then arrived at the payment button, which is the experience the
            // whole feature exists to prevent.
            //
            // Decided server-side, because this is a decision rather than a presentation: a
            // hidden control is still a control, and the sheet must not offer what the shop will
            // refuse. True when no branch is chosen, which is every visitor before the gate is
            // answered and every shop that has never used Outlets.
            'servedHere'           => BranchScope::serves($dish->id),
            'branchName'           => BranchScope::name($locale),
            'uid'                  => $uid,
            // The operator's Custom CSS ID when they set one, and the generated handle when
            // they did not. The Vue app deliberately does NOT mount on this — see `$uid` in the
            // view — because an id an operator can retype is not something the ordering form
            // may depend on.
            'cssId'                => $shape['customId'] ?: $uid,
            'dishId'               => $dish->id,
            'heading'              => $title,
            'description'          => $description,
            'categoryLinks'        => $categoryLinks,
            'tags'                 => $tags,
            'variants'             => $variants,
            'groups'               => $groups,
            'payload'              => $payload,
            'shareNetworks'        => $shareNetworks,
            'showNotes'            => (bool) ($data['show_notes'] ?? true),
            'notesLabel'           => $this->translate($data['notes_label'] ?? '', $locale) ?: __('Special instructions'),
            'notesMax'             => $notesMax,
            'isAvailable'          => $isAvailable,
            'soldOutLabel'         => $soldOutLabel,
            // Theme-wide, not a per-block control: Saved Dishes is one feature with one
            // switch, the same one the dish card and the header read.
            'showWishlist'         => ThemeSettings::bool('wishlist_enabled', true),
            'imageUrl'             => $this->primaryImageUrl($dish),
            'reviewSummary'        => $this->reviewSummary($dish),
            'showReviews'          => $this->interactions->commentsEnabled()
                && ThemeSettings::bool('reviews_enabled', true),
            // Every photograph, for the gallery and the lightbox. `imageUrl` stays as the
            // first of these because the share card and the JSON-LD want one image, not a set.
            'images'               => $this->galleryUrls($dish),
            'locale'               => $locale,
            'data'                 => $data,
        ]))->render();
    }

    /**
     * Everything the block's **layout** panel controls, resolved into the classes the view uses.
     *
     * Core's Product Details schema carries `layout_style` and the two sidebar switches, and
     * until now this theme read none of them: Saffron claims the block's renderer, core's schema
     * is not overridable by a theme (`SectionController` namespaces theme schemas under
     * `_THEME_SECTION`), and so the panel an operator sees on a dish page drove nothing at all.
     * They set Layout 03, saved, reloaded, and got the same page back. This method is the half
     * that was missing; nothing else about the sheet changed.
     *
     * The Dish Sheet block reads the same keys from its own schema, so the two blocks are one
     * vocabulary rather than a fork.
     *
     * @return array<string, mixed>
     */
    protected function shape(array $data): array
    {
        $layout = (string) ($data['layout_style'] ?? 'default');

        if (! in_array($layout, self::LAYOUTS, true)) {
            $layout = 'default';
        }

        $sidebarProps = [
            'show_categories' => (bool) ($data['sidebar_show_categories'] ?? true),
            'show_featured'   => (bool) ($data['sidebar_show_featured'] ?? true),
        ];

        // A sidebar layout with both panels switched off has no sidebar. Rendering the column
        // anyway would leave a third of the page empty and the sheet squeezed into two-thirds
        // for no visible reason, so the switches collapse the column rather than its contents.
        $hasSidebar = in_array($layout, ['left_sidebar', 'right_sidebar'], true)
            && ($sidebarProps['show_categories'] || $sidebarProps['show_featured']);

        return [
            'layout'      => $layout,
            'hasSidebar'  => $hasSidebar,
            'sidebarSide' => $layout === 'left_sidebar' ? 'left' : 'right',
            'sidebarProps' => $sidebarProps,

            // Layouts 02, 08 and 09 are wider than the theme's 1240px measure. The modifier
            // goes on the section's own container AND is what `_helpers.scss` matches with
            // `:has()` to widen core's `.cms-section-wrapper`, which is the element that
            // actually caps a builder row on a dish page.
            'containerClass' => match ($layout) {
                'full_width'                    => 'saffron-container saffron-container--fluid',
                'left_sidebar', 'right_sidebar' => 'saffron-container saffron-container--wide',
                default                         => 'saffron-container',
            },

            // With a rail the sheet takes nine columns and its two halves split inside them at
            // md; without one it is the full row and splits at lg, which is what it has always
            // done.
            'mainColClass'  => $hasSidebar ? 'col-12 col-lg-9' : 'col-12',
            'mediaColClass' => $hasSidebar ? 'col-12 col-md-6' : 'col-12 col-lg-6',
            'infoColClass'  => $hasSidebar ? 'col-12 col-md-6' : 'col-12 col-lg-6',

            // Empty string rather than a generated id: the caller decides what to fall back to,
            // because the two branches of `render()` have different handles.
            'customId' => trim((string) ($data['style']['section']['id'] ?? '')),
            'cssClass' => trim((string) ($data['style']['section']['class'] ?? '')),
        ];
    }

    /**
     * The dish this sheet configures.
     *
     * `View::shared('page')` is how a section reaches the record ThemeController resolved.
     * On a Product page that is the dish itself; anywhere else the section must name one.
     */
    protected function resolveDish(array $data): ?Product
    {
        $eager = ['variants', 'tags', 'categories', 'assets'];

        if (!empty($data['product_id'])) {
            return Product::with($eager)->whereKey((int) $data['product_id'])->whereNull('productable_id')->first();
        }

        $page = View::shared('page') ?? null;

        if ($page instanceof Product) {
            return $page->loadMissing($eager);
        }

        return null;
    }

    /**
     * The dish's modifier groups, in pivot order, with their answers.
     *
     * `required_override` on the pivot wins over the group's own `min_select`, so the same
     * group can be compulsory on one dish and optional on another without being duplicated.
     */
    protected function resolveGroups(Product $dish, string $locale): array
    {
        // The table ships with this theme's migrations. If a storefront renders before they
        // have run — a half-deployed import, a stale cache — degrade to no groups rather than
        // throwing on a missing table and taking the whole dish page down.
        try {
            $groups = ModifierGroup::query()
                ->where('modifier_groups.status', 'active')
                ->whereHas('dishes', fn ($q) => $q->whereKey($dish->id))
                ->with(['modifiers' => fn ($q) => $q->where('status', 'active')])
                ->join('dish_modifier_group', 'dish_modifier_group.modifier_group_id', '=', 'modifier_groups.id')
                ->where('dish_modifier_group.product_id', $dish->id)
                ->orderBy('dish_modifier_group.orders')
                ->select('modifier_groups.*', 'dish_modifier_group.required_override as pivot_required_override')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return $groups->map(function (ModifierGroup $group) use ($locale) {
            $override = $group->pivot_required_override;
            $override = $override === null ? null : (bool) $override;

            $required = $group->isRequired($override);
            $max      = $group->effectiveMaxSelect();

            return [
                'id'          => $group->id,
                'slug'        => $group->slug,
                // The options-bag key. It is the group's TITLE, not its slug, because this
                // string is what the kitchen reads off the ticket and what the customer sees
                // on the receipt — a slug would be an internal identifier leaking onto both.
                'key'         => $this->translate($group->title, $locale) ?: $group->slug,
                'title'       => $this->translate($group->title, $locale) ?: $group->slug,
                'description' => trim(strip_tags((string) $this->translate($group->description, $locale))),
                'selection'   => $group->selection,
                'required'    => $required,
                'min_select'  => $required ? max(1, (int) $group->min_select) : 0,
                'max_select'  => $max,
                'modifiers'   => $group->modifiers->map(fn (\Theme\Backend\Models\Modifier $m) => [
                    'id'          => $m->id,
                    'label'       => $this->translate($m->title, $locale),
                    'price_delta' => (float) $m->price_delta,
                    'is_default'  => (bool) $m->is_default,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * The share targets, in Ella's order and with Ella's URLs, filtered by the shop's
     * `share_*` Application settings.
     *
     * The switches are core's, not this theme's, which is the point: an operator turns
     * Pinterest off once on Settings → Application and it disappears from Saffron's dish sheet
     * and Ella's product page together. A network missing from the settings row defaults to
     * on, matching Ella's `?? true`.
     *
     * `mailto:`, `sms:` and `viber:` are deliberately kept in the list even though they are not
     * web pages — the view refuses to open those in a popup window and lets the device's own
     * app take them.
     */
    protected function shareNetworks(array $appSettings, string $url, string $title, string $image): array
    {
        $u   = urlencode($url);
        $t   = urlencode($title);
        $img = $image === '' ? '' : urlencode(url($image));

        $networks = [
            ['facebook',    'Facebook',    "https://www.facebook.com/sharer/sharer.php?u={$u}"],
            ['twitter',     'X (Twitter)', "https://twitter.com/intent/tweet?url={$u}&text={$t}"],
            ['linkedin',    'LinkedIn',    "https://www.linkedin.com/sharing/share-offsite/?url={$u}"],
            ['whatsapp',    'WhatsApp',    "https://wa.me/?text={$t}%20{$u}"],
            ['pinterest',   'Pinterest',   "https://pinterest.com/pin/create/button/?url={$u}&media={$img}&description={$t}"],
            ['telegram',    'Telegram',    "https://t.me/share/url?url={$u}&text={$t}"],
            ['reddit',      'Reddit',      "https://reddit.com/submit?url={$u}&title={$t}"],
            ['email',       'Email',       "mailto:?subject={$t}&body={$t}%20{$u}"],
            ['tumblr',      'Tumblr',      "https://www.tumblr.com/widgets/share/tool?canonicalUrl={$u}&title={$t}"],
            ['vk',          'VKontakte',   "https://vk.com/share.php?url={$u}&title={$t}"],
            ['xing',        'Xing',        "https://www.xing.com/spi/shares/new?url={$u}"],
            ['line',        'Line',        "https://social-plugins.line.me/lineit/share?url={$u}"],
            ['viber',       'Viber',       "viber://forward?text={$t}%20{$u}"],
            ['skype',       'Skype',       "https://web.skype.com/share?url={$u}"],
            ['weibo',       'Weibo',       "https://service.weibo.com/share/share.php?url={$u}&title={$t}"],
            ['hackernews',  'Hacker News', "https://news.ycombinator.com/submitlink?u={$u}&t={$t}"],
            ['pocket',      'Pocket',      "https://getpocket.com/save?url={$u}&title={$t}"],
            ['flipboard',   'Flipboard',   "https://share.flipboard.com/bookmarklet/popout?v=2&title={$t}&url={$u}"],
            ['instapaper',  'Instapaper',  "https://www.instapaper.com/hello2?url={$u}&title={$t}"],
            ['evernote',    'Evernote',    "https://www.evernote.com/clip.action?url={$u}&title={$t}"],
            ['trello',      'Trello',      "https://trello.com/add-card?url={$u}&name={$t}"],
            ['mix',         'Mix',         "https://mix.com/add?url={$u}"],
            ['digg',        'Digg',        "https://digg.com/submit?url={$u}&title={$t}"],
            ['blogger',     'Blogger',     "https://www.blogger.com/blog-this.g?u={$u}&n={$t}"],
            ['sms',         'SMS',         "sms:?&body={$t}%20{$u}"],
            ['threads',     'Threads',     "https://threads.net/intent/post?text={$t}%20{$u}"],
            ['tiktok',      'TikTok',      'https://www.tiktok.com/'],
            ['xiaohongshu', 'Xiaohongshu', 'https://www.xiaohongshu.com/'],
        ];

        $out = [];

        foreach ($networks as [$key, $label, $target]) {
            if (!($appSettings['share_' . $key] ?? true)) {
                continue;
            }

            $out[] = [
                'key'   => $key,
                'label' => $label,
                'url'   => $target,
                'icon'  => self::SHARE_ICONS[$key],
            ];
        }

        return $out;
    }

    /**
     * A variant's display label. Falls back to its option data, then its SKU — a size picker
     * showing "Product 4" because a title was never set is worse than showing the SKU.
     */
    protected function variantLabel(Product $variant, string $locale): string
    {
        $title = $this->translate($variant->title, $locale);

        if ($title !== '') {
            return $title;
        }

        $optionValues = collect($variant->data ?? [])
            ->filter(fn ($v, $k) => is_scalar($v) && !in_array($k, ['compare_at_price', 'vendor'], true))
            ->values()
            ->all();

        if (!empty($optionValues)) {
            return implode(' / ', array_map('strval', $optionValues));
        }

        return (string) ($variant->sku ?: '—');
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }

    protected function primaryImageUrl(Product $dish): string
    {
        return $this->galleryUrls($dish)[0] ?? '';
    }

    /**
     * Every photograph of this dish, featured first.
     *
     * The sheet showed **one** image for its whole life — this method's predecessor returned a
     * single URL and the rest of `assets` was eager-loaded and then thrown away. A dish with
     * three photographs looked like a dish with one, on the page where a customer decides
     * whether to order it. Ella's product page has carried a gallery with thumbnails the entire
     * time (register E1).
     *
     * `PRODUCT_IMAGE` first and featured at the front of it, so the thumbnail order matches
     * what an operator arranged in admin. Falls back to every asset when nothing carries the
     * usage, because older rows predate it and a shop with photos should not see none.
     *
     * @return array<int,string>
     */
    protected function galleryUrls(Product $dish): array
    {
        $assets = $dish->assets->where('usage', 'PRODUCT_IMAGE')->sortByDesc('featured');

        if ($assets->isEmpty()) {
            $assets = $dish->assets;
        }

        return $assets
            ->map(fn ($asset) => $this->assetUrl((string) $asset->path))
            ->filter()
            // The same file attached twice would give the gallery two identical slides and a
            // thumbnail that highlights both.
            ->unique()
            ->values()
            ->all();
    }

    protected function assetUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/']) ? $path : '/storage/' . ltrim($path, '/');
    }
}
