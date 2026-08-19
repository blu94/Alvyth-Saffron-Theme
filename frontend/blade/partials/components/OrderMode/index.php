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

        // ── Dine in, and which table (O2) ───────────────────────────────────────
        // A third tile rather than a question buried inside the pickup flow, because that is
        // how a diner thinks about it and what spec §9.2 describes. **Core is still told
        // `pickup`**: a customer sitting in the room needs no address and pays no delivery fee,
        // and `fulfillment_type` only knows delivery and pickup. Which of the two kinds of
        // collection it is rides on `checkout_fields`, the theme's own bag — the same split as
        // everything else here, and the reason this needed no core change.
        //
        // Offered only where a shop actually has a dining room. Most takeaway shops do not, and
        // a tile that leads to "which table?" in a shop with no tables is worse than no tile.
        $dineIn = $offered !== 'delivery' && $this->bool($settings['offer_dine_in'] ?? false);

        // A list beats free text when the shop knows its own tables: a diner mistyping 21 for 12
        // sends the food to somebody else's table, and nothing downstream can catch it. Empty
        // means the tables are not numbered 1..N — a courtyard, named booths — so the customer
        // types whatever they are called.
        $tables = (int) ($settings['dine_in_tables'] ?? 0);
        $tables = $tables > 0 ? min($tables, 200) : 0;

        // ── Cutlery (O2) ────────────────────────────────────────────────────────
        // An opt-OUT, which is what the platforms that popularised it settled on: the default
        // stays what the shop already does, and only a customer who says so changes it. Asked
        // for delivery and collection alike — the waste is the same either way — but never for
        // dine-in, where the cutlery is already on the table.
        $askCutlery = $this->bool($settings['ask_cutlery'] ?? false);

        // The choices in the order they are offered. Two or more is a question; one is an
        // answer, and an answer belongs in a hidden input rather than a control the customer
        // cannot change — the rule this component already applied to a single-mode shop, now
        // applied to the dine-in tile as well.
        $modes = array_values(array_filter([
            $offered !== 'pickup'   ? 'delivery' : null,
            $offered !== 'delivery' ? 'pickup'   : null,
            $dineIn                 ? 'dine_in'  : null,
        ]));

        $payload = [
            'offered' => $offered,
            'modes'   => $modes,
            'labels'  => [
                'heading'  => __('How do you want it'),
                'delivery' => __('Delivery'),
                'pickup'   => __('Pickup'),
                'dineIn'   => __('Dine in'),
                'deliveryHint' => __('Brought to your address'),
                'pickupHint'   => __('Collect it from us'),
                'dineInHint'   => __('Eat with us'),
                'collectFrom'  => __('Collect from'),
                'chooseOutlet' => __('Which branch?'),
                'chooseTable'  => __('Which table?'),
                'tablePlaceholder' => __('e.g. 12'),
                'tableOption'  => __('Table :number'),
                'noCutlery'    => __('I do not need cutlery'),
                'noCutleryHint' => __('Helps us cut down on waste'),
                'ready'    => $this->translate($settings['pickup_ready_label'] ?? '', $locale)
                    ?: __('Ready to collect in about 20 minutes'),
            ],
            'pickupAddress' => $pickupAddress,
            'outlets'       => $outlets,
            'dineIn'        => $dineIn,
            'tables'        => $tables,
            'askCutlery'    => $askCutlery,
        ];

        return View::make($themeViewPath, [
            'offered'       => $offered,
            'pickupAddress' => $pickupAddress,
            'payload'       => $payload,
            'outlets'       => $outlets,
            // More than one is what makes it a choice. One is an answer, and an answer belongs
            // in a hidden input, not a control with a single option.
            'showPicker'    => count($outlets) > 1,
            'dineIn'        => $dineIn,
            'askCutlery'    => $askCutlery,
            'modes'         => $modes,
            'tables'        => $tables,
            'uid'           => 'saffron-order-mode',
        ])->render();
    }

    /** A switch setting, read the way every other theme switch is. */
    protected function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
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
