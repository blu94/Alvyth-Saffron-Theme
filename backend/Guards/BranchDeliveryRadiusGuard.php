<?php

namespace Theme\Backend\Guards;

use App\Contracts\Storefront\CheckoutGuard;
use Theme\Backend\Models\Outlet;

/**
 * Refuses a delivery no branch's own riders can reach (register O7b, per-branch half).
 *
 * **The operator's ruling is the spec, and it is what makes this per branch rather than a
 * setting:** asked whether delivery is own riders or a courier, the answer was *"both,
 * depending on branch"*. So reach is a property of the branch — one branch's riders serve a
 * circle around it, another hands the parcel to a courier whose coverage the shop does not
 * define and must not pretend to.
 *
 * That asymmetry decides the whole rule:
 *
 * - A branch with **own riders** states its reach: `delivers_by_own_riders` plus a positive
 *   `delivery_radius_km`, measured from the branch's own coordinates.
 * - A branch using a **courier** states nothing. Its reach is whatever the shipping zones say,
 *   which core already prices and already refuses ("We do not ship to the selected destination"),
 *   so this guard has no opinion about it.
 *
 * Therefore: **the order is refused only when every branch that could have delivered it runs its
 * own riders, and none of their circles contains the address.** One courier branch anywhere in
 * the shop means the shop can still get the parcel there, and this guard stands down — the zones
 * are the authority for that arrangement, not this class. A shop that has ticked the switch
 * nowhere is every shop before this feature existed, and is never judged.
 *
 * **What it can see, and the honest limit.** `lat`/`lng` reach a guard only for an address the
 * customer SAVED, because core geocodes in the address book and deliberately never in the payment
 * path — a map lookup between a customer and their payment is the objection radius zones were
 * built to avoid. A guest typing an address at checkout carries a postcode and no point, so this
 * guard cannot judge them and says nothing. That is not a hole this class opened: core's own
 * radius shipping zones have exactly the same reach, and a radius that silently became a refusal
 * for everybody would be far worse than one that narrows for the customers it can actually
 * measure.
 *
 * Fails open like every guard in this seam, and here that is doubly deliberate: an address the
 * shop cannot locate, a branch nobody geocoded, a missing column after a half-run migration —
 * each costs the guard its opinion, never the shop its order.
 */
class BranchDeliveryRadiusGuard implements CheckoutGuard
{
    public function check(array $cart, array $fields, array $context = []): ?string
    {
        // Collection and dine-in go to a branch the customer picked, not to an address. Core
        // sends no destination for them at all; this is belt as well as braces.
        if (($context['fulfillment_type'] ?? 'delivery') !== 'delivery') {
            return null;
        }

        $lat = $context['lat'] ?? null;
        $lng = $context['lng'] ?? null;

        // No point to measure against — a guest, or a saved address the geocoder could not
        // place. Silence, not refusal: see the class note.
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        // Only branches a customer could actually be served by. The same rule the branch-menu
        // and dine-in readers keep: scope may only be granted by a branch that is open for
        // business, or deactivating one would start refusing deliveries in its name.
        $delivering = Outlet::query()->active()->where('offers_delivery', true)->get();

        if ($delivering->isEmpty()) {
            return null;
        }

        // A branch that does not state its own reach is served by a courier, whose coverage the
        // shipping zones own. One of those is enough for the shop to reach anywhere they cover.
        if ($delivering->contains(fn (Outlet $outlet) => ! $outlet->ridesItsOwn())) {
            return null;
        }

        // Every delivering branch rides its own. If any circle contains the address, somebody
        // can take it.
        foreach ($delivering as $outlet) {
            if ($outlet->deliversTo((float) $lat, (float) $lng)) {
                return null;
            }
        }

        // Nobody reaches it. Name the distance to the nearest branch rather than only refusing:
        // a customer told "too far" with no figure cannot tell whether they are 200 metres or
        // 20 km outside, and the shop's own riders are exactly the arrangement where being just
        // outside is worth a phone call.
        return $this->refusal($delivering, (float) $lat, (float) $lng);
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Outlet>  $delivering
     */
    private function refusal(\Illuminate\Support\Collection $delivering, float $lat, float $lng): ?string
    {
        $nearest = $delivering
            ->map(fn (Outlet $outlet) => ['outlet' => $outlet, 'km' => $outlet->distanceKmTo($lat, $lng)])
            ->filter(fn (array $row) => $row['km'] !== null)
            ->sortBy('km')
            ->first();

        if (! $nearest) {
            // Every branch rides its own riders and none of them has been geocoded, so the
            // circles exist only on paper. Refusing on that would be refusing on a
            // misconfiguration the customer cannot see or fix.
            //
            // **Null, not an empty string.** The seam treats any returned string as a refusal,
            // so `''` would refuse the order and hand the cart's error strip nothing to print —
            // a customer turned away by a blank sentence. Caught by
            // `a_branch_with_no_coordinates_draws_no_circle`, which is exactly the case it was
            // written for.
            return null;
        }

        $outlet = $nearest['outlet'];
        $title  = $outlet->getTranslation('title', app()->getLocale(), false)
            ?: $outlet->getTranslation('title', 'en', false);

        return __(
            'That address is outside our delivery area. Our nearest branch, :branch, delivers up to :radius km and you are about :distance km away. Collection is still available.',
            [
                'branch'   => trim((string) $title) ?: (string) $outlet->slug,
                'radius'   => rtrim(rtrim(number_format((float) $outlet->delivery_radius_km, 1), '0'), '.'),
                'distance' => rtrim(rtrim(number_format((float) $nearest['km'], 1), '0'), '.'),
            ]
        );
    }
}
