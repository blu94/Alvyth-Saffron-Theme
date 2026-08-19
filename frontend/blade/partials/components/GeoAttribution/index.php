<?php

namespace Theme\Components;

use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;

/**
 * The OpenStreetMap credit, where the shop geocodes addresses with OpenStreetMap data.
 *
 * **This is a licence obligation, not a courtesy.** Nominatim and Photon both serve
 * OpenStreetMap data, which is ODbL: visible attribution is required wherever that data is
 * used. Core's Address Geocoding settings promise this surface outright — the switch is
 * labelled *"Show OpenStreetMap Credit On The Storefront"* — and until this component existed
 * the switch was on by default and rendered nothing, so a shop reading its own settings screen
 * would have believed it was complying when it was not.
 *
 * Shown only when it is actually owed:
 *
 *  - the provider is one of the two OpenStreetMap services (a shop on Google or Mapbox credits
 *    them through their own SDK terms, and `off` uses nobody's data at all), and
 *  - the operator has not turned the credit off — which the settings copy tells them to do only
 *    when they are on a paid provider.
 *
 * Read from **core's** application settings rather than the theme's, because the provider is a
 * shop-wide policy that survives a theme change: swapping themes must not quietly drop a legal
 * notice. `ThemeSettings` is the wrong well for it.
 *
 * Fails silent. A storefront rendering against a half-migrated install should lose a credit
 * line, not the footer — and a shop that cannot read its settings is not geocoding either, so
 * nothing is owed in that case anyway.
 */
class GeoAttribution
{
    /** The providers whose data carries the ODbL obligation. */
    private const OSM_PROVIDERS = ['nominatim', 'photon'];

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        try {
            $settings = app(ApplicationInterface::class)->getSettings();
        } catch (\Throwable $e) {
            report($e);

            return '';
        }

        $provider = strtolower(trim((string) ($settings['geocode_provider'] ?? 'nominatim')));

        if (! in_array($provider, self::OSM_PROVIDERS, true)) {
            return '';
        }

        // Defaults to on, matching the setting's own default: a shop that has never opened the
        // screen is using Nominatim and owes the credit.
        $show = $settings['geocode_attribution'] ?? true;
        $show = filter_var($show, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        if (! $show) {
            return '';
        }

        return View::make($themeViewPath)->render();
    }
}
