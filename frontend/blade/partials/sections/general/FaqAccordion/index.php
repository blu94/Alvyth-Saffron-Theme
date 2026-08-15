<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\Motion;

/**
 * Delivery areas, allergens, payment — the questions a shop answers twenty times a day.
 *
 * Authored content only. Rendered as native <details> elements: keyboard accessible, works
 * with JavaScript disabled, and needs no Vue app for what is a disclosure widget.
 */
class FaqAccordion
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => ($item['status'] ?? 'active') === 'active')
            ->map(fn ($item, $index) => [
                'uid'      => 'faq-' . $index . '-' . Str::random(5),
                'question' => $this->translate($item['question'] ?? '', $locale),
                'answer'   => $this->translate($item['answer'] ?? '', $locale),
                'open'     => (bool) ($item['open_by_default'] ?? false),
            ])
            ->filter(fn ($item) => $item['question'] !== '')
            ->values();

        return View::make($themeViewPath, [
            'heading'    => $this->translate($data['heading'] ?? '', $locale),
            'subheading' => $this->translate($data['subheading'] ?? '', $locale),
            'items'      => $items,
            'locale'     => $locale,
            'motionAttrs' => Motion::sectionAttributes($data),
            'data'       => $data,
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
