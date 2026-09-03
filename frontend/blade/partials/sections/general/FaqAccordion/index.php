<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Support\Motion;
use Theme\Backend\Support\ThemeSettings;

/**
 * Delivery areas, allergens, payment — the questions a shop answers twenty times a day.
 *
 * Authored content only. Rendered as native <details> elements: keyboard accessible, works
 * with JavaScript disabled, and needs no Vue app for what is a disclosure widget.
 */
class FaqAccordion
{
    /** Per-request section counter — deterministic uids, so ETag revalidation can match. */
    private static int $uidSequence = 0;

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        // The section's Status control — Disabled renders nothing (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        // The random suffix kept two FAQ sections on one page from colliding on $index —
        // and made every render byte-unique, killing ETag revalidation (audit P5's rule).
        // A per-section counter does the first job without the second cost.
        $section = ++self::$uidSequence;

        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => ($item['status'] ?? 'active') === 'active')
            ->map(fn ($item, $index) => [
                'uid'      => 'faq-' . $section . '-' . $index,
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
            'jsonLd'     => $this->faqPageJsonLd($items),
            'locale'     => $locale,
            'motionAttrs' => Motion::sectionAttributes($data),
            'data'       => $data,
        ])->render();
    }

    /**
     * schema.org `FAQPage` for the questions shown, printed beside them so a page carries
     * the markup exactly when it shows the section (audit B1). Empty — and the blade prints
     * nothing — when the theme's Structured Data switch is off or no answered question
     * remains. JSON_HEX_TAG keeps an answer containing "</script>" inside the block.
     */
    protected function faqPageJsonLd($items): string
    {
        $settings = ThemeSettings::all();

        if (! filter_var($settings['structured_data_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        $entities = collect($items)
            ->filter(fn ($item) => trim((string) $item['answer']) !== '')
            ->map(fn ($item) => [
                '@type'          => 'Question',
                'name'           => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ])
            ->values()
            ->all();

        if ($entities === []) {
            return '';
        }

        return (string) json_encode(
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities],
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
}
