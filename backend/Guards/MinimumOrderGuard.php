<?php

namespace Theme\Backend\Guards;

use App\Contracts\Storefront\CheckoutGuard;
use Theme\Backend\Support\ThemeSettings;

/**
 * A minimum order value that depends on how the order is being fulfilled.
 *
 * Core already has one global **Minimum Cart Spend**, and it applies to every order however it
 * reaches the customer. That is the wrong shape for a restaurant: a delivery carries a rider
 * and a fee and normally needs a floor, while a collection costs the shop nothing extra and
 * normally has none. Expressing "RM 20 for delivery, nothing for pickup" was impossible.
 *
 * **A second guard rather than more of `ServiceWindowGuard`.** That class answers one question —
 * are we open for this, then. This one answers a different one, and the registry takes a list
 * precisely so refusals can compose. Two small guards each about one thing beat one that has
 * to be read twice to find out what it does.
 *
 * **Measured before the delivery fee**, because that is how a customer reads a minimum: "spend
 * RM 20" means on food, not on food plus the fee that spending brings. Core hands guards the
 * priced cart lines, so the subtotal here is the server's own arithmetic and not the browser's.
 *
 * Fails open like every guard: an unreadable setting is no minimum, not a refused order.
 */
class MinimumOrderGuard implements CheckoutGuard
{
    public function check(array $cart, array $fields, array $context = []): ?string
    {
        $mode = $context['fulfillment_type'] ?? 'delivery';
        $mode = in_array($mode, ['delivery', 'pickup'], true) ? $mode : 'delivery';

        $minimum = $this->minimumFor($mode);

        // No minimum configured is the default and the common case — most shops set one for
        // delivery only, and many set none at all.
        if ($minimum === null || $minimum <= 0) {
            return null;
        }

        $subtotal = 0.0;

        foreach ($cart as $line) {
            // The cart has been through a JSON round trip on its way here, so `15.0` arrives
            // as `15` — cast rather than assume, exactly as the checkout-guard contract warns.
            $subtotal += ((float) ($line['price'] ?? 0)) * ((int) ($line['quantity'] ?? 0));
        }

        if ($subtotal >= $minimum) {
            return null;
        }

        // A diner is recorded as collecting — `fulfillment_type` knows only two values — so the
        // pickup floor is the right figure for them. The pickup *sentence* is not: telling
        // somebody at a table that "collection orders start at" an amount, and inviting them to
        // switch to delivery, describes a shop they are not in. The mode decides the money; the
        // `dining` field decides the words, the same key `BranchDineInGuard` reads.
        if ($mode === 'pickup') {
            return ($fields['dining'] ?? null) === 'dine_in'
                ? __('Table orders start at :amount. Please add a little more.', [
                    'amount' => $this->money($minimum),
                ])
                : __('Collection orders start at :amount. Please add a little more.', [
                    'amount' => $this->money($minimum),
                ]);
        }

        return __('Delivery orders start at :amount. Please add a little more, or switch to pickup.', [
            'amount' => $this->money($minimum),
        ]);
    }

    /** The configured floor for this mode, or null when the operator set none. */
    private function minimumFor(string $mode): ?float
    {
        $value = ThemeSettings::get($mode === 'pickup' ? 'minimum_pickup' : 'minimum_delivery');

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * The amount as the shop writes it.
     *
     * Read from the application's own currency settings rather than hardcoded, so a refusal
     * does not quote RM at a shop trading in dollars.
     */
    private function money(float $amount): string
    {
        $settings = app(\App\Repositories\Setting\Application\ApplicationInterface::class)->getSettings();

        $symbol   = $settings['currency_symbol'] ?? '$';
        $position = $settings['currency_position'] ?? 'prefix';
        $formatted = number_format($amount, 2);

        return $position === 'suffix' ? $formatted . $symbol : $symbol . $formatted;
    }
}
