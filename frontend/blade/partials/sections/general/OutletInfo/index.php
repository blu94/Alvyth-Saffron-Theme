<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Models\ServiceWindow;
use Theme\Backend\Support\Motion;

/**
 * Where the shop is, how to reach it, and the week's hours.
 *
 * Hours are read from `service_windows` and grouped by day, so the footer's free-text summary
 * and this table cannot drift apart. Several rows on one day render as split service —
 * "11:00–14:30, 18:00–22:00" — which is exactly why the table allows them.
 */
class OutletInfo
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data     = $data ?? [];
        $settings = View::shared('settings') ?? [];

        $showHours = (bool) ($data['show_hours'] ?? true);
        $days      = [];

        if ($showHours) {
            try {
                // Recurring rows only. Exceptions share the table (kind = exception) with
                // day_of_week normalised to null — and Collection::where() compares loosely,
                // so null == 0 made every dated holiday render as a permanent Sunday row.
                $windows = ServiceWindow::where('status', 'active')
                    ->recurring()
                    ->whereNull('scope_id')
                    ->orderBy('day_of_week')
                    ->orderBy('opens_at')
                    ->get();

                // Monday-first reading order, which is how opening hours are read almost
                // everywhere, while the column itself stays 0 = Sunday to match Carbon.
                foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
                    $spans = $windows->where('day_of_week', $dow)
                        ->map(fn ($w) => substr((string) $w->opens_at, 0, 5) . '–' . substr((string) $w->closes_at, 0, 5))
                        ->values()
                        ->all();

                    $days[] = [
                        'label' => ServiceWindow::DAYS[$dow],
                        'spans' => $spans,
                    ];
                }

                // Nothing authored at all: show no table rather than seven "Closed" rows.
                if ($windows->isEmpty()) {
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
