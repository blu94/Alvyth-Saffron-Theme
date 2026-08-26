<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Repositories\ServiceWindowRepository;
use Theme\Backend\Support\BranchScope;
use Theme\Backend\Support\Motion;
use Theme\Backend\Support\ThemeSettings;

/**
 * Open / closed / opens-at banner.
 *
 * Reads the theme's own `service_windows`, computed in the SHOP's timezone so a 09:00 opening
 * is 09:00 there whatever the season — unless the visitor's chosen branch keeps hours of its
 * own, in which case the banner answers for that branch, on that branch's clock where one is
 * set. The branch is the gate's (`BranchScope`), the same one every listing is scoped by, so
 * the banner cannot say Open about a menu whose branch is shut.
 *
 * Presentation — but no longer presentation *only*. `openState()` is now also what
 * `Theme\Backend\Guards\ServiceWindowGuard` asks before checkout accepts an ASAP order, so this
 * banner and that refusal cannot disagree: both resolve through
 * `ServiceWindowRepository::hoursForDate()`. Saying "Open" while the server turns the customer
 * away would be worse than either alone.
 */
class StoreStatus
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

        // A missing table (a half-run migration, a stale import) must not take the whole page
        // down over a banner. Degrade to "no opinion" and render nothing. The gate's branch is
        // resolved inside the try for the same reason — it reads a theme table too.
        try {
            $outletId = BranchScope::outlet()?->id;

            // The section's own timezone override wins where set; otherwise the repository
            // answers — the branch's zone for a branch keeping its own hours, else the shop's.
            $timezone = (string) ($data['timezone'] ?? '') ?: $this->windows->timezone($outletId);

            $state = $this->windows->openState($timezone, null, $outletId);
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
            'motionAttrs'   => Motion::sectionAttributes($data),
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
