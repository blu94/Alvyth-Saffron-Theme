<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Support\Motion;

/**
 * A row of promo tiles — a discount code, a bundle, a delivery threshold.
 *
 * Purely authored content: no query, no data model. Promo codes themselves are core
 * `Discount` records; this only advertises them.
 */
class PromoStrip
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        // The section's Status control — Disabled renders nothing (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => ($item['status'] ?? 'active') === 'active')
            ->map(fn ($item) => [
                'eyebrow' => $this->translate($item['eyebrow'] ?? '', $locale),
                'title'   => $this->translate($item['title'] ?? '', $locale),
                'text'    => $this->translate($item['text'] ?? '', $locale),
                'code'    => trim((string) ($item['code'] ?? '')),
                'link'    => is_array($item['link'] ?? null) ? (string) ($item['link']['url'] ?? '') : (string) ($item['link'] ?? ''),
                'icon'    => (string) ($item['icon'] ?? ''),
            ])
            ->filter(fn ($item) => $item['title'] !== '' || $item['text'] !== '')
            ->values();

        $cols = match ((string) ($data['columns'] ?? '3')) {
            '2' => 'col-12 col-md-6',
            '4' => 'col-12 col-sm-6 col-lg-3',
            default => 'col-12 col-md-6 col-lg-4',
        };

        return View::make($themeViewPath, [
            'heading' => $this->translate($data['heading'] ?? '', $locale),
            'items'   => $items,
            'colClass' => $cols,
            'locale'  => $locale,
            'motionAttrs' => Motion::sectionAttributes($data),
            'data'    => $data,
        ])->render();
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
