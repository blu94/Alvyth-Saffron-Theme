<?php

namespace Theme\Sections\General;

use App\Repositories\Product\ProductInterface;
use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\Motion;
use Theme\Backend\Support\ThemeSettings;

/**
 * A strip of signature dishes gliding across the page — the featured-dish scroller
 * fine-dining sites open with, built on dishes that already exist as Products.
 *
 * Selection is the same three-way pick DishGrid offers (newest / category slug / tag
 * slug), so an operator who has learned one has learned the other. Each dish becomes a
 * light card — photo, name, a line of description, the price — deliberately without the
 * DishCard's Add button: a strip that never stops moving is a poor place to start an
 * order, so every card links to the dish sheet instead.
 *
 * The strip is CSS-animated and works with JavaScript off; motion.js only tops the track
 * up with clones when the shop has too few dishes to fill the width. Speed and direction
 * reach the stylesheet through @push('dynamic_styles') on the section's own id, the same
 * route Ella's ticker takes, so no inline style attribute is ever printed.
 */
class DishMarquee
{
    /** Per-request render counter — deterministic uids, so ETag revalidation can match. */
    private static int $uidSequence = 0;

    public function __construct(
        protected ProductInterface $productRepo,
        protected ApplicationInterface $appSettingsRepo
    ) {}

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        $source     = $data['source'] ?? 'latest';
        $sourceSlug = Str::slug(trim((string) ($data['source_slug'] ?? '')));
        $limit      = max(1, min(24, (int) ($data['limit'] ?? 8)));

        $query = $this->productRepo
            ->baseIndexQuery(['status' => 'active'])
            ->with(['variants']);

        // Slug is translatable and stored as JSON, so it is matched with whereJsonContains
        // semantics against both the active locale and the English fallback — the same
        // lookup DishGrid uses.
        if ($source === 'category' && $sourceSlug !== '') {
            $query->whereHas('categories', function ($q) use ($sourceSlug, $locale) {
                $q->where('categories.status', 'active')
                    ->where(function ($inner) use ($sourceSlug, $locale) {
                        $inner->where("categories.slug->{$locale}", $sourceSlug)
                            ->orWhere('categories.slug->en', $sourceSlug);
                    });
            });
        } elseif ($source === 'tag' && $sourceSlug !== '') {
            $query->whereHas('tags', function ($q) use ($sourceSlug, $locale) {
                $q->where('tags.status', 'active')
                    ->where(function ($inner) use ($sourceSlug, $locale) {
                        $inner->where("tags.slug->{$locale}", $sourceSlug)
                            ->orWhere('tags.slug->en', $sourceSlug);
                    });
            });
        }

        $appSettings      = $this->appSettingsRepo->getSettings();
        $currencySymbol   = $appSettings['currency_symbol'] ?? '$';
        $currencyPosition = $appSettings['currency_position'] ?? 'prefix';

        $showPrice       = filter_var($data['show_price'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $showDescription = filter_var($data['show_description'] ?? true, FILTER_VALIDATE_BOOLEAN);

        // The branch narrows the query. A strip that never stops moving is the worst place to
        // advertise a dish this branch cannot make: it links to a sheet that then refuses to sell.
        BranchScope::constrain($query);

        $dishes = $query
            ->orderByDesc('products.created_at')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get()
            ->map(fn ($dish) => [
                'title'       => $this->translate($dish->title, $locale),
                'url'         => $dish->store_url,
                'image'       => $this->primaryImageUrl($dish),
                'description' => $showDescription
                    ? Str::limit(strip_tags((string) $this->translate($dish->description, $locale)), 90)
                    : '',
                'price'       => $showPrice ? $this->priceLabel($dish, $currencySymbol, $currencyPosition) : null,
                // Run out at this branch tonight. Greyed and badged rather than dropped from the
                // strip, because {@see BranchScope::soldOut()} is a list to grey WITH and never a
                // list to hide BY — the same treatment the dish card and the dish sheet give it.
                // Until this existed the strip advertised it at full price, unbadged, and linked
                // to a page that would not sell it.
                'soldOut'     => ! BranchScope::inStock((int) $dish->id),
            ])
            ->filter(fn ($dish) => $dish['title'] !== '')
            ->values();

        $ctaUrl = is_array($data['cta_url'] ?? null)
            ? (string) ($data['cta_url']['url'] ?? '')
            : (string) ($data['cta_url'] ?? '');

        // The operator's own wording, from the same theme setting the cards and the sheet read —
        // one place to change it, rather than a fourth spelling of "Sold out".
        $soldOutLabel = $this->translate(ThemeSettings::all()['sold_out_label'] ?? '', $locale)
            ?: __('Sold out for today');

        return View::make($themeViewPath, [
            'soldOutLabel' => $soldOutLabel,
            'heading'      => $this->translate($data['heading'] ?? '', $locale),
            'subheading'   => $this->translate($data['subheading'] ?? '', $locale),
            'dishes'       => $dishes,
            'ctaLabel'     => $this->translate($data['cta_label'] ?? '', $locale),
            'ctaUrl'       => $ctaUrl !== '' ? $ctaUrl : '/',
            'sizeClass'    => match ((string) ($data['card_size'] ?? 'regular')) {
                'compact' => 'saffron-marquee--compact',
                'large'   => 'saffron-marquee--large',
                default   => '',
            },
            'pauseClass'   => filter_var($data['pause_on_hover'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'saffron-marquee--pause' : '',
            'speed'        => in_array((string) ($data['speed'] ?? '40'), ['25', '40', '60'], true) ? (int) $data['speed'] : 40,
            'reverse'      => ($data['direction'] ?? 'left') === 'right',
            'source'       => $source,
            'sourceSlug'   => $sourceSlug,
            // Unique per render so two strips on one page carry their own speed rules and
            // fill independently — but deterministic, because a random suffix made every
            // render byte-unique and killed ETag revalidation (audit P5's rule).
            'uid'          => 'saffron-marquee-' . ++self::$uidSequence,
            'motionAttrs'  => Motion::sectionAttributes($data),
            'locale'       => $locale,
            'data'         => $data,
        ])->render();
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }

    /**
     * The card's price line. A dish sized Regular / Large is one Product with variants,
     * so the strip shows the cheapest as a "from" price — the same rule DishCard applies,
     * because a parent's own figure may match nothing the customer can actually order.
     *
     * @return array{amount: string, from: bool}|null  null when nothing priced is on sale
     */
    protected function priceLabel(mixed $dish, string $symbol, string $position): ?array
    {
        $basePrice     = (float) ($dish->price ?? 0);
        $variantPrices = $dish->variants
            ->where('status', 'active')
            ->map(fn ($variant) => (float) ($variant->price ?? 0))
            ->filter(fn ($price) => $price > 0)
            ->values();

        $displayPrice = $basePrice;
        $isFromPrice  = false;

        if ($variantPrices->isNotEmpty()) {
            $displayPrice = (float) $variantPrices->min();
            $isFromPrice  = $variantPrices->unique()->count() > 1
                || ($basePrice > 0 && abs($basePrice - $displayPrice) > 0.001);
        }

        // What the branch being browsed charges. Applied as a shift to whatever figure was
        // resolved above, exactly as `DishCard` applies it and from the same resolver — so a
        // "from" price stays a "from" price and a strip and a grid showing the same dish on the
        // same page cannot quote two different numbers. Without this the marquee advertised the
        // shop's price while the cart charged the branch's.
        $shift = BranchScope::priceShift((int) $dish->id, $basePrice);

        if (abs($shift) > 0.0001) {
            $displayPrice = max(0.0, $displayPrice + $shift);
        }

        if ($displayPrice <= 0) {
            return null;
        }

        $formatted = number_format($displayPrice, 2);

        return [
            'amount' => $position === 'suffix' ? $formatted . $symbol : $symbol . $formatted,
            'from'   => $isFromPrice,
        ];
    }

    /**
     * The dish photo from the already-loaded asset collection — no query per card.
     * `Asset::path` is an accessor returning a full URL.
     */
    protected function primaryImageUrl(mixed $dish): string
    {
        $asset = $dish->assets
            ->where('usage', 'PRODUCT_IMAGE')
            ->sortByDesc('featured')
            ->first()
            ?? $dish->assets->first();

        if (!$asset) {
            return '';
        }

        $path = (string) $asset->path;

        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/'])
            ? $path
            : '/storage/' . ltrim($path, '/');
    }
}
