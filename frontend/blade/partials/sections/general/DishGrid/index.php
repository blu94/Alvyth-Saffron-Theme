<?php

namespace Theme\Sections\General;

use App\Repositories\Product\ProductInterface;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\Motion;
use Theme\Backend\Support\SectionSetting;
use Theme\Backend\Support\ThemeSettings;

/**
 * One curated row of dishes — "Most Popular", "New This Week", "Chef's Picks".
 *
 * Section schemas cannot fetch options from the database, so the operator names a category
 * or tag by slug rather than picking one from a list. That keeps the whole selection a
 * plain string and needs no new endpoint.
 *
 * "Most popular" is deliberately NOT computed from order history here. Ranking dishes by
 * sales is a reporting query on order_items, and running it per page render on a shared
 * host is the wrong place for it — the honest phase-1 mechanism is a tag the shop applies.
 */
class DishGrid
{
    public function __construct(
        protected ProductInterface $productRepo
    ) {}

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        // The section's Status control — Disabled renders nothing (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        // Theme-wide dish-card defaults (Restaurant tab). A curated row keeps its own
        // layout and column count — those are the row's shape, not the menu's — but the
        // card toggles resolve section → theme like the menu's do (audit A8).
        $settings = ThemeSettings::all();

        $heading    = $this->translate($data['heading'] ?? '', $locale);
        $subheading = $this->translate($data['subheading'] ?? '', $locale);

        $source     = $data['source'] ?? 'latest';
        $sourceSlug = Str::slug(trim((string) ($data['source_slug'] ?? '')));
        $limit      = max(1, (int) ($data['limit'] ?? 8));
        $layout     = $data['layout'] ?? 'grid';
        $align      = $data['align'] ?? 'center';

        $ctaLabel = $this->translate($data['cta_label'] ?? '', $locale);
        $ctaUrl   = is_array($data['cta_url'] ?? null)
            ? (string) ($data['cta_url']['url'] ?? '')
            : (string) ($data['cta_url'] ?? '');

        $query = $this->productRepo
            ->baseIndexQuery(['status' => 'active'])
            ->with(['variants']);

        // Slug is translatable and stored as JSON, so it is matched with whereJsonContains
        // against both the active locale and the English fallback rather than a LIKE.
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

        // The branch narrows the query, so a curated row cannot promote a dish this branch
        // cannot make. Applied before the limit: filtering after it would silently shorten the
        // row instead of filling it with dishes that are actually orderable.
        BranchScope::constrain($query);

        $dishes = $query
            ->orderByDesc('products.created_at')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get();

        return View::make($themeViewPath, [
            'heading'      => $heading,
            'subheading'   => $subheading,
            'dishes'       => $dishes,
            'layout'       => $layout,
            'align'        => $align === 'start' ? 'start' : 'center',
            'gridColClass' => $this->gridColClass((string) ($data['columns_desktop'] ?? '4')),
            'ctaLabel'     => $ctaLabel,
            'ctaUrl'       => $ctaUrl !== '' ? $ctaUrl : '/',
            'ratioClass'   => $this->ratioClass($data['image_ratio'] ?? ''),
            'sourceSlug'   => $sourceSlug,
            'source'       => $source,
            'cardSettings' => [
                'layout'           => $layout,
                'show_price'       => SectionSetting::bool($data['show_price'] ?? null, $settings['dish_show_price'] ?? null, true),
                'show_tags'        => SectionSetting::bool($data['show_tags'] ?? null, $settings['dish_show_tags'] ?? null, true),
                // A curated row is a teaser, so its built-in fallback is no description —
                // but the theme's switch, when set, still wins over that fallback.
                'show_description' => SectionSetting::bool($data['show_description'] ?? null, $settings['dish_show_description'] ?? null, false),
                'show_add_button'  => $data['show_add_button'] ?? true,
                'add_button_label' => $data['add_button_label'] ?? 'Add',
                'sold_out_label'   => $settings['sold_out_label'] ?? '',
            ],
            'motionAttrs'  => Motion::sectionAttributes($data),
            'data'         => $data,
            'locale'       => $locale,
        ])->render();
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }

    protected function gridColClass(string $colsDesktop): string
    {
        return match ($colsDesktop) {
            '2' => 'col-12 col-sm-6 col-lg-6',
            '3' => 'col-6 col-md-6 col-lg-4',
            default => 'col-6 col-md-4 col-lg-3',
        };
    }

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
