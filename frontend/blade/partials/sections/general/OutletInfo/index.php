<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Models\ServiceWindow;
use Theme\Backend\Repositories\ServiceWindowRepository;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\Motion;
use Theme\Backend\Support\ThemeSettings;

/**
 * Where the shop is, how to reach it, and the week's hours.
 *
 * Hours come from `ServiceWindowRepository::weeklyPattern()` — the same resolution the banner,
 * the picker and the checkout guard read — so this table cannot drift from any of them. When
 * the visitor's chosen branch keeps hours of its own, the table shows that branch's week; a
 * day such a branch has not authored renders as Closed there, which is what it is. Several
 * spans on one day render as split service — "11:00–14:30, 18:00–22:00" — which is exactly
 * why the table allows them.
 */
class OutletInfo
{
    public function __construct(
        protected ServiceWindowRepository $windows
    ) {}

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data     = $data ?? [];
        $settings = ThemeSettings::all();

        // The section's Status control — Disabled renders nothing (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        $showHours = (bool) ($data['show_hours'] ?? true);
        $days      = [];

        if ($showHours) {
            try {
                // The pattern already excludes exceptions (a dated holiday must not render as
                // a permanent weekday row) and resolves whose week this is — the gate branch's
                // own, when it keeps one, else the shop's.
                $pattern = $this->windows->weeklyPattern(BranchScope::outlet()?->id);

                // Monday-first reading order, which is how opening hours are read almost
                // everywhere, while the keys stay 0 = Sunday to match Carbon.
                foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
                    $days[] = [
                        'label' => ServiceWindow::DAYS[$dow],
                        'spans' => collect($pattern[$dow] ?? [])
                            ->map(fn (array $span) => $span['opens'] . '–' . $span['closes'])
                            ->all(),
                    ];
                }

                // Nothing authored at all: show no table rather than seven "Closed" rows.
                if (collect($pattern)->every(fn (array $spans) => $spans === [])) {
                    $days = [];
                }
            } catch (\Throwable $e) {
                report($e);
                $days = [];
            }
        }

        return View::make($themeViewPath, [
            'heading' => $this->translate($data['heading'] ?? '', $locale) ?: __('Find us'),
            'address' => $this->translate($data['address'] ?? '', $locale) ?: $this->translate($settings['footer_address'] ?? '', $locale),
            'phone'   => (string) ($data['phone'] ?? '') ?: (string) ($settings['footer_phone'] ?? ''),
            'email'   => (string) ($data['email'] ?? ''),
            'mapUrl'  => is_array($data['map_url'] ?? null) ? (string) ($data['map_url']['url'] ?? '') : (string) ($data['map_url'] ?? ''),
            'note'    => $this->translate($data['note'] ?? '', $locale),
            'days'    => $days,
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
