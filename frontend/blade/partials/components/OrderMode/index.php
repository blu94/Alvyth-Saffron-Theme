<?php

namespace Theme\Components;

use Illuminate\Support\Facades\View;
use Theme\Backend\Models\Outlet;
use Theme\Backend\Support\ThemeSettings;

/**
 * Delivery or pickup — the choice that decides whether an order needs an address.
 *
 * **Why this can exist at all.** Until core gained a fulfilment type, whether checkout demanded
 * an address was decided by `Product.requires_shipping`, a column on the shared catalogue. So a
 * shop had to pick one mode for everything it sold: flag a dish shippable and every collection
 * order was refused for want of an address; flag it not-shippable and no delivery fee could
 * ever be charged. There was no third setting (spec §3, §14 item 3, register O4). The order now
 * names its own type and that wins, so one menu can genuinely be offered both ways.
 *
 * **The control is the theme's, the contract is core's.** Core's Cart section reads
 * `[data-checkout-mode]` from anywhere on the page and posts it as `fulfillment_type` — the
 * same shape as `[data-checkout-field]`, which this theme already uses for the scheduled time.
 * That split is deliberate: how a restaurant presents "deliver it or come and get it" is a
 * presentation decision, and a bookshop offering click-and-collect wants a quieter one.
 *
 * **Renders nothing when there is no choice to make.** A delivery-only or pickup-only shop gets
 * a single hidden input carrying its one mode rather than a picker with one option — the
 * server still learns which it is, and the customer is not asked a question with one answer.
 */
class OrderMode
{
    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = ThemeSettings::all();

        $offered = (string) ($settings['ordering_modes'] ?? 'both');
        $offered = in_array($offered, ['both', 'delivery', 'pickup'], true) ? $offered : 'both';

        // The collection address falls back to the footer's, because a shop that filled in one
        // address should not have to fill it in twice to turn pickup on.
        $pickupAddress = trim((string) $this->translate($settings['pickup_address'] ?? '', $locale));

        if ($pickupAddress === '') {
            $pickupAddress = trim((string) $this->translate($settings['footer_address'] ?? '', $locale));
        }

        // ── Which branch to collect from (Q5) ───────────────────────────────────
        // The picker lives HERE, inside the pickup flow, because that is where you said it
        // belongs: "if user choose pick up by themselve, need to show branch option that allow
        // user to pick, but if admin only add 1 outlet, then no need to show outlet options."
        //
        // So one outlet renders no picker at all — its address simply becomes the collection
        // address — which is the same rule this component already applies to a single-mode shop.
        // A shop that has never created an outlet is unchanged in every respect.
        $outlets = $this->collectionOutlets($locale);

        $payload = [
            'offered' => $offered,
            'labels'  => [
                'heading'  => __('How do you want it'),
                'delivery' => __('Delivery'),
                'pickup'   => __('Pickup'),
                'deliveryHint' => __('Brought to your address'),
                'pickupHint'   => __('Collect it from us'),
                'collectFrom'  => __('Collect from'),
                'chooseOutlet' => __('Which branch?'),
                'ready'    => $this->translate($settings['pickup_ready_label'] ?? '', $locale)
                    ?: __('Ready to collect in about 20 minutes'),
            ],
            'pickupAddress' => $pickupAddress,
            'outlets'       => $outlets,
        ];

        return View::make($themeViewPath, [
            'offered'       => $offered,
            'pickupAddress' => $pickupAddress,
            'payload'       => $payload,
            'outlets'       => $outlets,
            // More than one is what makes it a choice. One is an answer, and an answer belongs
            // in a hidden input, not a control with a single option.
            'showPicker'    => count($outlets) > 1,
            'uid'           => 'saffron-order-mode',
        ])->render();
    }

    /**
     * The branches a customer may collect from, default first.
     *
     * Wrapped in a try/catch because `outlets` ships with this theme's migrations: a storefront
     * rendering against a half-deployed import or a stale cache must fall back to no outlets —
     * which is exactly the shop-with-one-address behaviour that already worked — rather than
     * taking the cart page down on a missing table. The same guard `DishSheet` puts round
     * `modifier_groups`, for the same reason.
     *
     * @return array<int,array{id:int,title:string,address:string,phone:string}>
     */
    protected function collectionOutlets(string $locale): array
    {
        try {
            return Outlet::active()
                ->pickup()
                // Default first so the picker opens on the branch the shop nominated, then the
                // operator's own arrangement.
                ->orderByDesc('is_default')
                ->ordered()
                ->get()
                ->map(fn (Outlet $outlet) => [
                    'id'      => $outlet->id,
                    'title'   => $this->translate($outlet->title, $locale) ?: $outlet->slug,
                    'address' => trim((string) $outlet->address),
                    'phone'   => trim((string) $outlet->phone),
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Resolve a translatable setting that may arrive as a raw string or a locale map. */
    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
