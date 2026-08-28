<?php

namespace Theme\Backend\Writers;

use App\Contracts\Storefront\OrderWriter;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Theme\Backend\Models\Outlet;

/**
 * Records which branch cooks a delivery order — the half the Kitchen Queue's branch filter
 * found missing.
 *
 * ## The gap this closes
 *
 * A branch reaches an order from the **pickup method's** meta: core copies a method's declared
 * checkout fields onto any order that selects it, so a collection or dine-in order carries
 * `checkout_fields.outlet_id` and the kitchen ticket can say *@ Bangsar*. A delivery order
 * selects a *delivery* method, which has no outlet, so it carried nothing at all. Measured on
 * the development database before this existed: of 498 open orders, **all 332 collection and
 * dine-in orders named a branch and none of the 166 delivery orders did.**
 *
 * Nothing anywhere recorded which kitchen was cooking a third of the shop's work. The queue's
 * branch filter had to keep every such order on every branch's board — the honest answer while
 * the fact did not exist, and no substitute for the fact.
 *
 * ## The rule, and it is one an operator can predict
 *
 * A branch is chosen only from those that could actually have taken the order — **active, and
 * offering delivery**. Among those:
 *
 * 1. **One candidate** → it cooks. The single-branch shop, which is most shops, and it never
 *    depends on coordinates being filled in.
 * 2. **Several, and the address has a point** → the **nearest** branch by straight-line
 *    distance, the same measure `BranchDeliveryRadiusGuard` refuses by, so the branch that is
 *    told to cook it is the one that was allowed to reach it.
 * 3. **Several, no usable point** → the branch marked **default**, if it is one of the
 *    candidates. A guest's typed address has no coordinates (core geocodes in the address book
 *    and deliberately never in the payment path), so this is the ordinary case, not the edge.
 * 4. **Otherwise** → nothing is written. Several branches, no point, no default among them:
 *    there is no answer, and inventing one puts a ticket on a counter that has no reason to
 *    believe it. The order stays branchless and appears on every board, exactly as before.
 *
 * ## Two decisions worth stating
 *
 * **It never overrides a branch the customer chose.** If the bag already names `outlet_id` the
 * order is a collection or dine-in and the branch is the customer's own answer. Routing exists
 * for the case where nobody was asked.
 *
 * **It is written to its own key, `kitchen_outlet_id`, and that is not tidiness.**
 * `outlet_id` means *the branch the customer picked*, and three guards read it that way —
 * `BranchMenuGuard`, `BranchDineInGuard` and the per-branch hours reader. Writing a routed
 * branch into that key would start judging delivery orders against a branch the customer never
 * chose, which is precisely what
 * `BranchMenuTest::a_delivery_order_is_not_judged_against_a_branch_it_never_chose` exists to
 * forbid. Two facts, two keys; the readers that want either read that one.
 *
 * > [!IMPORTANT]
 * > **This writer must never refuse an order, which makes it the opposite of the seam's other
 * > one.** `TableReservation` throws deliberately: a booking that cannot be recorded must not
 * > become an order, because the customer would be given a table nothing is holding. Routing
 * > carries no such promise — it decides which of the shop's own counters sees a ticket, and a
 * > shop that cannot decide should still take the money and put the order on every board. So
 * > every failure here is caught and logged, and the order proceeds unrouted. A shop that
 * > stopped accepting deliveries because a coordinate was missing would be a far worse outcome
 * > than a ticket somebody has to claim by hand.
 */
class DeliveryKitchenRouting implements OrderWriter
{
    public function write(Order $order): void
    {
        try {
            $this->route($order);
        } catch (\Throwable $e) {
            // Never refuse. See the class note — this is the deliberate inverse of
            // TableReservation, and the catch is the whole of that decision.
            Log::warning('Saffron could not route a delivery order to a branch', [
                'order_id' => $order->id,
                'message'  => $e->getMessage(),
            ]);

            // **Record that it was tried and failed**, or this catch becomes the thing it is
            // meant to guard against. See `stamp()`.
            $this->stamp($order, null, self::ROUTING_FAILED);
        }
    }

    /** An attempt was made and a branch was chosen. */
    public const ROUTING_ROUTED = 'routed';

    /** The shop has no active branch offering delivery — nothing to choose between. */
    public const ROUTING_NO_BRANCHES = 'no_branches';

    /** Several candidates, no geocoded address, and no default among them. No answer exists. */
    public const ROUTING_UNRESOLVED = 'unresolved';

    /** Something threw. The order stands; the routing did not happen. */
    public const ROUTING_FAILED = 'failed';

    private function route(Order $order): void
    {
        if ($order->fulfillmentType() !== Order::FULFILLMENT_TYPE_DELIVERY) {
            return;
        }

        $fields = $order->meta['checkout_fields'] ?? [];

        // The customer already named a branch, so there is nothing to decide. Belt as well as
        // braces: a delivery order should not carry one at all.
        //
        // **Precedence worth stating rather than discovering:** core merges a pickup method's
        // own `meta.checkout_fields` into this bag *server-side, winning over the client's*. No
        // shipping method carries `kitchen_outlet_id` today, so there is no collision — but if
        // one ever did, the method would win and this early return would read it as already
        // routed. Do not put this key on a shipping method.
        if (! empty($fields['outlet_id']) || ! empty($fields['kitchen_outlet_id'])) {
            return;
        }

        $candidates = Outlet::query()->active()->where('offers_delivery', true)->get();

        // No outlets, or none delivering: this is every shop before the table existed, and the
        // queue behaves for it exactly as it always has.
        if ($candidates->isEmpty()) {
            $this->stamp($order, null, self::ROUTING_NO_BRANCHES);

            return;
        }

        $chosen = $candidates->count() === 1
            ? $candidates->first()
            : ($this->nearest($candidates, $order) ?? $this->defaultAmong($candidates));

        $this->stamp(
            $order,
            $chosen?->id,
            $chosen ? self::ROUTING_ROUTED : self::ROUTING_UNRESOLVED
        );
    }

    /**
     * Write the branch **and how it was arrived at**, or the reason there is none.
     *
     * **Why the outcome is recorded and not merely the success.** Without it, `kitchen_outlet_id`
     * is absent in four different situations — the customer had already chosen a branch, the shop
     * has none that deliver, no rule produced an answer, and *something threw and was swallowed*
     * — and to anybody reading the order they are the same absence. That is the defect this
     * codebase keeps meeting from new directions: **"I could not find out" and "there was nothing
     * to find" producing the same output.** It is what made a caught SQL error read as "every
     * table is free", and what makes a missing `ps` read as "no process running".
     *
     * The log line is not a substitute. Logs are read once somebody already suspects a problem,
     * and a kitchen quietly failing to route every order is precisely the problem nobody would
     * think to suspect. The key rides on the order, so it also appears in the admin order form's
     * *Checkout Answers* list with no extra screen: an operator seeing unrouted tickets can tell
     * a shop that has configured no delivering branches from one whose routing is throwing.
     */
    private function stamp(Order $order, ?int $outletId, string $state): void
    {
        try {
            $meta   = $order->meta ?? [];
            $fields = $meta['checkout_fields'] ?? [];

            if ($outletId !== null) {
                $fields['kitchen_outlet_id'] = (string) $outletId;
            }

            $fields['kitchen_routing'] = $state;

            // `meta` is an array cast, so the bag is rewritten rather than merged — the shape
            // every other writer of this column uses. Saved inside the order's transaction.
            $meta['checkout_fields'] = $fields;

            $order->meta = $meta;
            $order->save();
        } catch (\Throwable $e) {
            // The stamp is diagnostics. It must never be the thing that refuses an order —
            // including on the path that is already handling a failure.
            Log::warning('Saffron could not record a delivery routing outcome', [
                'order_id' => $order->id,
                'state'    => $state,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * The nearest candidate to the order's shipping address, or null when it cannot be measured.
     *
     * Coordinates exist only for an address the customer **saved** — core geocodes in
     * `AddressRepository` and never in the payment path — so a guest carries none and this
     * answers null, which is what sends the decision to the default branch below.
     */
    private function nearest(\Illuminate\Support\Collection $candidates, Order $order): ?Outlet
    {
        $address = $order->shippingAddress;

        if (! $address || ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
            return null;
        }

        $lat = (float) $address->latitude;
        $lng = (float) $address->longitude;

        return $candidates
            ->map(fn (Outlet $outlet) => ['outlet' => $outlet, 'km' => $outlet->distanceKmTo($lat, $lng)])
            // A branch nobody geocoded cannot be measured; it is not therefore the nearest.
            ->filter(fn (array $row) => $row['km'] !== null)
            ->sortBy('km')
            ->first()['outlet'] ?? null;
    }

    /** The shop's default branch, but only when it is one of the branches that could deliver. */
    private function defaultAmong(\Illuminate\Support\Collection $candidates): ?Outlet
    {
        return $candidates->firstWhere('is_default', true);
    }
}
