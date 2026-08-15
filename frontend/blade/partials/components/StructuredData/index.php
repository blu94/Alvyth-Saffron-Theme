<?php

namespace Theme\Components;

use App\Models\Product;
use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Models\ServiceWindow;
use Theme\Backend\Support\ThemeSettings;

/**
 * schema.org markup for the page, printed once in the layout's <head>.
 *
 * A restaurant lives on the search features this feeds — hours in the knowledge panel,
 * price and availability on a dish result — and the theme already holds every fact those
 * need: the outlet's identity and contact details in the footer settings, its hours in
 * `service_windows`, each dish as a Product with price, image and stock. Nothing new is
 * asked of the operator; this only says what the theme already knows, in the vocabulary
 * search engines read (audit B1).
 *
 * Emits one `@graph`:
 *   - `Restaurant` on every page — name, url, logo, telephone, email, address, social
 *     profiles as `sameAs`, and `openingHoursSpecification` from the recurring whole-shop
 *     service windows (the same rows OutletInfo prints, so the two cannot disagree).
 *   - `Product` on a dish page — name, description, images, sku, and an `Offer` (or an
 *     `AggregateOffer` across sizes) whose availability mirrors the stock rule core enforces
 *     in `validateCart`: stock 0 is OutOfStock, anything else InStock.
 *
 * `BreadcrumbList` and `FAQPage` are emitted next to the markup they describe (the
 * Breadcrumbs component and the FaqAccordion section) rather than here, so a page carries
 * them exactly when it shows them.
 *
 * The page's own SEO tab may hold hand-written JSON-LD; the layout prints that separately
 * and this does not read it. Turned off as a whole by the General → Search Engines switch,
 * for a shop whose SEO plugin already publishes these entities.
 */
class StructuredData
{
    public function __construct(
        protected ApplicationInterface $appSettingsRepo
    ) {}

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = $data['settings'] ?? ThemeSettings::all();

        if (! filter_var($settings['structured_data_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        $appSettings = $this->appSettingsRepo->getSettings();
        $page        = $data['page'] ?? View::shared('page');

        $graph = [$this->restaurant($settings, $appSettings, $locale)];

        if ($page instanceof Product) {
            $graph[] = $this->product($page, $appSettings, $locale);
        }

        return View::make($themeViewPath, [
            'json' => $this->encode(['@context' => 'https://schema.org', '@graph' => $graph]),
        ])->render();
    }

    /**
     * The outlet. `@id` is stable across pages so a crawler merges every page's copy into
     * one entity rather than seeing a new restaurant per URL.
     */
    protected function restaurant(array $settings, array $appSettings, string $locale): array
    {
        $entity = [
            '@type' => 'Restaurant',
            '@id'   => url('/') . '#restaurant',
            'name'  => (string) ($appSettings['site_title'] ?? config('app.name', 'Ovynt')),
            'url'   => url('/'),
        ];

        $logo = $this->absoluteUrl($this->imagePath($settings['header_logo'] ?? null));
        if ($logo !== '') {
            $entity['logo']  = $logo;
            $entity['image'] = $logo;
        }

        $phone = trim((string) ($settings['footer_phone'] ?? '')) ?: trim((string) ($appSettings['contact_phone'] ?? ''));
        if ($phone !== '') {
            $entity['telephone'] = $phone;
        }

        $email = trim((string) ($appSettings['contact_email'] ?? ''));
        if ($email !== '') {
            $entity['email'] = $email;
        }

        // The footer address is one free-text field, and schema.org accepts `address` as
        // plain text — better an honest string than a PostalAddress guessed from commas.
        $address = trim($this->translate($settings['footer_address'] ?? '', $locale));
        if ($address !== '') {
            $entity['address'] = $address;
        }

        $sameAs = collect($settings['footer_socials'] ?? [])
            ->map(fn ($s) => is_array($s['url'] ?? null) ? (string) ($s['url']['url'] ?? '') : (string) ($s['url'] ?? ''))
            ->map(fn ($u) => trim($u))
            ->filter(fn ($u) => Str::startsWith($u, ['http://', 'https://']))
            ->unique()
            ->values()
            ->all();
        if ($sameAs !== []) {
            $entity['sameAs'] = $sameAs;
        }

        $hours = $this->openingHours();
        if ($hours !== []) {
            $entity['openingHoursSpecification'] = $hours;
        }

        return $entity;
    }

    /**
     * Recurring, whole-shop windows only — the same filter OutletInfo applies. Rows with
     * identical times are folded into one specification listing several days, which is
     * how the vocabulary expects a "Mon–Fri 11:00–22:00" to be written.
     *
     * A missing table (a half-run migration) must not cost the page its markup: the
     * restaurant entity is still emitted, just without hours.
     */
    protected function openingHours(): array
    {
        try {
            $windows = ServiceWindow::where('status', 'active')
                ->recurring()
                ->whereNull('scope_id')
                ->orderBy('day_of_week')
                ->orderBy('opens_at')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return $windows
            ->filter(fn ($w) => isset(ServiceWindow::DAYS[$w->day_of_week]) && $w->opens_at && $w->closes_at)
            ->groupBy(fn ($w) => substr((string) $w->opens_at, 0, 5) . '|' . substr((string) $w->closes_at, 0, 5))
            ->map(function ($group, $key) {
                [$opens, $closes] = explode('|', $key, 2);

                return [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => $group->pluck('day_of_week')->unique()->sort()->map(fn ($d) => ServiceWindow::DAYS[$d])->values()->all(),
                    'opens'     => $opens,
                    'closes'    => $closes,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A dish. Sizes are variants — Products of their own with their own price and stock —
     * so a sized dish is one `AggregateOffer` spanning them, and an unsized dish is one
     * `Offer` on the parent.
     */
    protected function product(Product $dish, array $appSettings, string $locale): array
    {
        $dish->loadMissing(['variants', 'assets']);

        $currency = strtoupper(trim((string) ($appSettings['currency_iso'] ?? ''))) ?: 'USD';
        $url      = $this->absoluteUrl((string) $dish->store_url);

        $entity = [
            '@type' => 'Product',
            '@id'   => $url . '#product',
            'name'  => $this->translate($dish->title, $locale),
            'url'   => $url,
        ];

        $description = trim(strip_tags((string) $this->translate($dish->description, $locale)));
        if ($description !== '') {
            $entity['description'] = Str::limit($description, 300);
        }

        $images = $dish->assets
            ->where('usage', 'PRODUCT_IMAGE')
            ->sortByDesc('featured')
            ->map(fn ($asset) => $this->absoluteUrl($this->imagePath($asset->path)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($images !== []) {
            $entity['image'] = $images;
        }

        if (! empty($dish->sku)) {
            $entity['sku'] = (string) $dish->sku;
        }

        $variants = $dish->variants->where('status', 'active');

        if ($variants->isEmpty()) {
            $entity['offers'] = $this->offer(
                (float) ($dish->price ?? 0),
                $dish->stock === null || (int) $dish->stock > 0,
                $currency,
                $url
            );

            return $entity;
        }

        $prices = $variants->map(fn ($v) => (float) ($v->price ?? 0))->filter(fn ($p) => $p > 0);
        $anyInStock = $variants->contains(fn ($v) => $v->stock === null || (int) $v->stock > 0);

        $entity['offers'] = [
            '@type'         => 'AggregateOffer',
            'priceCurrency' => $currency,
            'lowPrice'      => number_format((float) ($prices->min() ?? 0), 2, '.', ''),
            'highPrice'     => number_format((float) ($prices->max() ?? 0), 2, '.', ''),
            'offerCount'    => $variants->count(),
            'availability'  => $anyInStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => $url,
        ];

        return $entity;
    }

    protected function offer(float $price, bool $inStock, string $currency, string $url): array
    {
        return [
            '@type'         => 'Offer',
            'price'         => number_format($price, 2, '.', ''),
            'priceCurrency' => $currency,
            'availability'  => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => $url,
        ];
    }

    /**
     * Encoded for a <script> body: JSON_HEX_TAG turns `<` and `>` into escapes, so a dish
     * description containing "</script>" cannot end the block early.
     */
    protected function encode(array $graph): string
    {
        return (string) json_encode(
            $graph,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }

    /**
     * An image setting arrives as a path string, a `{path}` object, or a one-item list of
     * them — the same three shapes the header logo already handles.
     */
    protected function imagePath(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[0]['path'] ?? ($value['path'] ?? '');
        }

        $path = trim((string) ($value ?? ''));

        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/']) ? $path : '/storage/' . ltrim($path, '/');
    }

    protected function absoluteUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url($path);
    }
}
