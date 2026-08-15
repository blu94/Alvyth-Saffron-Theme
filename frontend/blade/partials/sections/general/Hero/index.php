<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Support\Motion;

/**
 * The opening banner. Slide data is **Ella-compatible on purpose**: a shop that
 * switches theme keeps the hero it already authored. When Saffron activated on a
 * shop whose HOME carried Ella's hero block, the type resolved to a class nobody
 * shipped and the front page opened with a missing-component strip — the block's
 * data was fine; only a renderer was absent. Same keys (slides → image / title /
 * subtitle / align / buttons / status), Saffron's own presentation.
 */
class Hero
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $slides = collect($data['slides'] ?? [])
            ->filter(fn ($slide) => ($slide['status'] ?? 'active') === 'active')
            ->map(fn ($slide) => [
                'image'        => (string) ($slide['image'] ?? ''),
                'image_tablet' => (string) ($slide['image_tablet'] ?? ''),
                'image_mobile' => (string) ($slide['image_mobile'] ?? ''),
                'title'        => $this->translate($slide['title'] ?? '', $locale),
                'subtitle'     => $this->translate($slide['subtitle'] ?? '', $locale),
                'align'        => in_array($slide['align'] ?? 'center', [
                    'top-left', 'top-center', 'top-right',
                    'center-left', 'center', 'center-right',
                    'bottom-left', 'bottom-center', 'bottom-right',
                ], true) ? $slide['align'] : 'center',
                'buttons'      => collect($slide['buttons'] ?? [])
                    ->filter(fn ($button) => ($button['status'] ?? 'active') === 'active')
                    ->map(fn ($button) => [
                        'text'   => $this->translate($button['text'] ?? '', $locale),
                        'url'    => is_array($button['link'] ?? null)
                            ? (string) ($button['link']['url'] ?? '')
                            : (string) ($button['link'] ?? ''),
                        'target' => is_array($button['link'] ?? null)
                            ? (string) ($button['link']['target'] ?? '_self')
                            : '_self',
                    ])
                    ->filter(fn ($button) => $button['text'] !== '' && $button['url'] !== '')
                    ->values(),
            ])
            ->filter(fn ($slide) => $slide['image'] !== '')
            ->values();

        return View::make($themeViewPath, [
            'slides'      => $slides,
            'heightClass' => match ((string) ($data['height'] ?? 'standard')) {
                'compact' => 'saffron-hero--compact',
                'tall'    => 'saffron-hero--tall',
                default   => '',
            },
            // Unique per render so two heroes on one page rotate independently.
            'uid'         => uniqid('saffron-hero-'),
            'locale'      => $locale,
            'motionAttrs' => Motion::sectionAttributes($data),
            'data'        => $data,
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
