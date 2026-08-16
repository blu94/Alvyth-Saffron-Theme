<?php

namespace Theme\Backend\Handlers;

use App\Contracts\Module\ModuleFieldHandler;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Theme\Backend\Repositories\ServiceWindowRepository;

/**
 * Kitchen State on the order's own edit screen — the same move the Kitchen Queue makes.
 *
 * The queue's Advance An Order card was the only place an order could go New → Preparing →
 * Ready → Out for delivery → Delivered, and the order form could not express Ready against
 * Out for delivery at all (one status pair). This tab puts that control on each order,
 * writing through {@see ServiceWindowRepository::moveKitchenOrder()} so both screens produce
 * the identical row: the status pair and `meta.kitchen_state`, together.
 *
 * Declared by `admin/extends/orders.json`. Core strips the declared keys before the order row
 * is saved and calls this afterwards, inside `OrderController`'s transaction — so a refused
 * move rolls the whole save back rather than committing half of it.
 *
 * **Only a change moves the order.** The form posts every field on every save, so this
 * receives a state whether or not the operator touched the tab. The tab also carries the
 * state it was opened at (`kitchen_state_was`, hidden), and the move happens only when the
 * two differ. Without that, saving an order whose Order Status had just been set to Cancelled
 * in the sidebar would meet the cancelled-order refusal and 422, and any sidebar status change
 * would be silently written back to whatever the tab still said. Whoever moved wins: an
 * untouched tab leaves Order Status and Fulfillment Status exactly as the sidebar saved them.
 */
class OrderKitchenState implements ModuleFieldHandler
{
    public function __construct(private ServiceWindowRepository $windows)
    {
    }

    /**
     * The state the order is in, for the select — and again for the hidden field that lets
     * `save()` tell an untouched tab from a moved one.
     *
     * Null for an order the kitchen has no state for (pending, on hold, cancelled with nothing
     * recorded): the select shows its placeholder rather than a state nobody chose.
     */
    public function load(Model $record): array
    {
        if (! $record instanceof Order) {
            return [];
        }

        $state = $this->windows->kitchenStateFor($record);

        return [
            'kitchen_state'     => $state,
            'kitchen_state_was' => $state,
        ];
    }

    /**
     * Move the order when the operator picked a different state; otherwise leave it alone.
     *
     * The unchanged branch has one job. Core's `OrderRepository::update()` writes `meta` from
     * the validated payload, and `validated()` keeps only the `meta.*` keys the schema names —
     * so an ordinary form save drops `meta.kitchen_state`, and Out for delivery would fall
     * back to Ready. When the stored key has gone and the status pair still matches the state
     * the tab showed, the key is put back. When the pair no longer matches, the sidebar moved
     * the order and the derived state is the truthful one, so nothing is written.
     */
    public function save(Model $record, array $values): void
    {
        if (! $record instanceof Order || ! array_key_exists('kitchen_state', $values)) {
            return;
        }

        $to  = $values['kitchen_state'] ?? null;
        $was = $values['kitchen_state_was'] ?? null;

        if (! is_string($to) || $to === '') {
            return;
        }

        if ($to !== $was) {
            $this->windows->moveKitchenOrder($record, $to);

            return;
        }

        $state = ServiceWindowRepository::KITCHEN_STATES[$to] ?? null;

        if ($state === null || isset($record->meta['kitchen_state'])) {
            return;
        }

        if ($record->status === $state['status'] && $record->fulfillment_status === $state['fulfillment_status']) {
            $record->fill(['meta' => array_merge($record->meta ?? [], ['kitchen_state' => $to])])->save();
        }
    }
}
