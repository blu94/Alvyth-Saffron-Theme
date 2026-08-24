<?php

namespace Theme\Backend\Handlers;

use App\Contracts\Module\ModuleFieldHandler;
use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Model;

/**
 * Links a pickup-type shipping method to the outlet it represents.
 *
 * Since pickup moved into core's shipping methods (one method per branch), the branch a
 * customer collects from is chosen by choosing the method — but the kitchen ticket, the
 * queue and the staff screens all name the branch by reading `meta.checkout_fields.outlet_id`
 * off the order. This handler is the bridge: it stores the outlet's id under the method's
 * own `meta.checkout_fields`, and core copies every key there onto any order that selects
 * the method (ShippingMethod::checkoutFields() → CheckoutController). Core never learns
 * what an outlet is; the key means something only to this theme, exactly like the rest of
 * the checkout-field bag.
 *
 * Declared by `admin/extends/shipping-methods.json`. Stored in meta rather than a column
 * because it is set once and read as data — the rule every set-and-display field follows.
 */
class ShippingMethodOutlet implements ModuleFieldHandler
{
    /**
     * Which outlet each active pickup method stands for.
     *
     * The storefront needs this map *before* an order exists: the branch a customer has chosen
     * is a method id in a radio group, and both the table picker and the booking-lead rule have
     * to turn that into an outlet in the browser. Reading `meta.checkout_fields.outlet_id`
     * belongs here rather than in each component, because this class is what put it there —
     * two components spelling out the same meta path is how one of them keeps the old spelling
     * after the other moves.
     *
     * Ids only, and nothing else about the method: this is a lookup, not a second branch picker.
     * Never throws — the callers are storefront drivers that must degrade to "no branch known"
     * rather than take a cart page down.
     *
     * @return array<int,int> shipping method id => outlet id
     */
    public static function map(): array
    {
        try {
            $map = [];

            $methods = ShippingMethod::query()
                ->where('type', ShippingMethod::TYPE_PICKUP)
                ->where('status', 'active')
                ->get();

            foreach ($methods as $method) {
                $outletId = $method->meta['checkout_fields']['outlet_id'] ?? null;

                if ($outletId !== null && $outletId !== '') {
                    $map[(int) $method->id] = (int) $outletId;
                }
            }

            return $map;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function load(Model $record): array
    {
        if (! $record instanceof ShippingMethod) {
            return [];
        }

        $value = $record->meta['checkout_fields']['outlet_id'] ?? null;

        return ['outlet_id' => $value !== null ? (int) $value : null];
    }

    public function save(Model $record, array $values): void
    {
        if (! $record instanceof ShippingMethod || ! array_key_exists('outlet_id', $values)) {
            return;
        }

        // Merge into meta rather than replace it: the column is shared (tracking-style
        // display data could live beside this), and clearing a sibling key because the
        // operator relinked a branch would be the classic meta-clobber bug.
        $meta   = $record->meta ?? [];
        $fields = is_array($meta['checkout_fields'] ?? null) ? $meta['checkout_fields'] : [];

        $outletId = $values['outlet_id'];

        if ($outletId === null || $outletId === '') {
            unset($fields['outlet_id']);
        } else {
            // Stored as a string: checkout-field values are strings end to end (the
            // request rules cap them at 255), and the kitchen reads it back loosely.
            $fields['outlet_id'] = (string) (int) $outletId;
        }

        if ($fields === []) {
            unset($meta['checkout_fields']);
        } else {
            $meta['checkout_fields'] = $fields;
        }

        $record->forceFill(['meta' => $meta ?: null])->save();
    }
}
