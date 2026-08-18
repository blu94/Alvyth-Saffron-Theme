<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Quotes from diners (register E9).
 *
 * **Read from the block's own repeater, not from a `testimonials` table**, and that is a
 * deliberate divergence from Ella. Ella ships both — a `testimonials` table with an admin module
 * *and* a repeater on the section — and its section driver reads only the repeater, so the table
 * and its whole module are dead weight on the storefront. Porting the table would mean shipping a
 * migration, a model and five schema files for data nothing renders.
 *
 * The repeater is also the better fit here: a shop's quotes are page furniture chosen for a
 * particular page, not a catalogue somebody browses. If a shop ever needs one library of quotes
 * reused across pages, that is the moment to add the table — and the section keeps working, because
 * the driver would then merge the two.
 *
 * A `draft` row is dropped rather than rendered faintly: an operator drafting a quote is removing
 * it from the page, which is the same reading `status` has everywhere else in this theme.
 */
class Testimonials
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        // The per-section Status control (audit A9). First line, before any work.
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => is_array($item) && ($item['status'] ?? 'active') === 'active')
            ->map(function (array $item) use ($locale) {
                $rating = (int) ($item['rating'] ?? 5);

                return [
                    'quote'  => trim(strip_tags((string) $this->translate($item['quote'] ?? '', $locale))),
                    'author' => trim((string) ($item['author'] ?? '')),
                    'role'   => trim((string) ($item['role'] ?? '')),
                    // Clamped rather than trusted: the select offers 0–5, but a hand-edited
                    // payload could ask for 9 and the blade would draw nine stars.
                    'rating' => max(0, min(5, $rating)),
                    'avatar' => $this->assetUrl($item['avatar'] ?? null),
                ];
            })
            // A row with no quote is an abandoned repeater entry, not a testimonial.
            ->filter(fn (array $item) => $item['quote'] !== '')
            ->values()
            ->all();

        $columns = (int) ($data['columns'] ?? 3);
        $columns = in_array($columns, [2, 3], true) ? $columns : 3;

        return View::make($themeViewPath, [
            'heading'    => $this->translate($data['heading'] ?? '', $locale),
            'subheading' => $this->translate($data['subheading'] ?? '', $locale),
            'items'      => $items,
            // Bootstrap's grid is what the layout already ships, so the column count becomes a
            // `col-md-*` span rather than a bespoke CSS grid nobody else in the theme uses.
            'colClass'   => $columns === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-lg-4',
            'align'      => ($data['align'] ?? 'center') === 'start' ? 'start' : 'center',
            'uid'        => 'testimonials-' . Str::random(6),
            'locale'     => $locale,
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

    /**
     * The avatar's URL.
     *
     * An `image` field can hand back a plain path, or the array the dropzone posts. Both shapes
     * are accepted because a repeater edited before and after an upload produces each in turn.
     */
    protected function assetUrl(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['path'] ?? $value['url'] ?? ($value[0]['path'] ?? '');
        }

        $path = trim((string) $value);

        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/']) ? $path : '/storage/' . ltrim($path, '/');
    }
}
