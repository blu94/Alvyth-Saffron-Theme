<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Repositories\ServiceWindowRepository;

/**
 * Open / closed / opens-at banner.
 *
 * Reads the theme's own `service_windows` and `service_exceptions`, computed in the SHOP's
 * timezone so a 09:00 opening is 09:00 there whatever the season.
 *
 * > This is PRESENTATION, NOT ENFORCEMENT. Nothing stops a direct POST to
 * > /storefront/checkout outside these hours. Refusing an out-of-hours order is
 * > RESTAURANT-THEME-SPEC.md §14 item 6, and it has to live in core.
 */
class StoreStatus
{
    public function __construct(
        protected ServiceWindowRepository $windows
    ) {}

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data     = $data ?? [];
        $settings = View::shared('settings') ?? [];

        $timezone = (string) ($data['timezone'] ?? '') ?: config('app.timezone', 'UTC');

        // A missing table (a half-run migration, a stale import) must not take the whole page
        // down over a banner. Degrade to "no opinion" and render nothing.
        try {
            $state = $this->windows->openState($timezone);
        } catch (\Throwable $e) {
            report($e);

            return '';
        }

        $closedMessage = $this->translate($data['closed_message'] ?? '', $locale)
            ?: $this->translate($settings['closed_message'] ?? '', $locale);

        $openMessage = $this->translate($data['open_message'] ?? '', $locale);

        return View::make($themeViewPath, [
            'isOpen'        => (bool) ($state['open'] ?? true),
            'reason'        => $state['reason'] ?? null,
            'nextOpen'      => $state['next_open'] ?? null,
            'nextDay'       => $state['next_day'] ?? null,
            'closesAt'      => $state['closes_at'] ?? null,
            'source'        => $state['source'] ?? 'window',
            'closedMessage' => $closedMessage,
            'openMessage'   => $openMessage,
            'showWhenOpen'  => (bool) ($data['show_when_open'] ?? false),
            'locale'        => $locale,
            'data'          => $data,
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
