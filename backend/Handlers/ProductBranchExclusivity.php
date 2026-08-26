<?php

namespace Theme\Backend\Handlers;

use App\Contracts\Module\ModuleFieldHandler;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Which branches a dish is exclusive to — authored on the dish, where the fact belongs.
 *
 * **The defect this closes is that exclusivity could not be stated at all.** The branch owned the
 * catalogue rule in both of its earlier shapes, and neither could express "this dish is only made
 * at KLCC". Under the first, a branch served only what was ticked, so saying it meant curating
 * every branch's entire menu. Under the second, a branch listed what it could not make, so saying
 * it meant visiting every *other* branch and excluding the dish there — miss one and the dish
 * leaks, and a branch opened next year leaks it by default. Exclusivity was emergent rather than
 * expressible, and an operator asked the obvious question: why can I not set this on the product?
 *
 * So the dish names its branches. Empty means available everywhere, which is the resting state of
 * almost every dish and of every shop that never opens this tab. Naming one branch makes the dish
 * exclusive to it; naming two makes it exclusive to those two and hides it from the third. That is
 * one statement, in one place, that stays true as branches are added.
 *
 * It writes the same `outlet_product` pivot the outlet screen reads — a row is "this dish is
 * available at this branch" — but it is the **only** writer. Two screens writing one pivot with
 * different ideas of what a row means is exactly how this went wrong twice, so the outlet form
 * shows the consequence read-only and nothing else.
 */
class ProductBranchExclusivity implements ModuleFieldHandler
{
    public const KEY = 'exclusive_outlet_ids';

    public function load(Model $record): array
    {
        if (! $record instanceof Product) {
            return [];
        }

        // A variant is a Product too, and it inherits its parent's availability rather than
        // carrying its own — a shop cannot sensibly serve Large in KLCC and Regular everywhere.
        // Reading the parent's row keeps the tab honest on a variant's own form.
        $id = $record->productable_id ?: $record->id;

        return [
            self::KEY => DB::table('outlet_product')
                ->where('product_id', $id)
                ->orderBy('outlet_id')
                ->pluck('outlet_id')
                ->map(fn ($outletId) => (int) $outletId)
                ->values()
                ->all(),
        ];
    }

    /**
     * A full replace, like the Modifiers tab beside it.
     *
     * Absent means the tab was not on the form at all and nothing is touched; **present and empty
     * means the operator cleared it**, and that is a decision to obey — the dish returns to being
     * available everywhere. Those two cases are distinguished by `array_key_exists`, never by
     * truthiness, because the form posts every field on every save and an emptied multi-select and
     * a missing one arrive looking almost the same.
     */
    public function save(Model $record, array $values): void
    {
        if (! $record instanceof Product || ! array_key_exists(self::KEY, $values)) {
            return;
        }

        // Availability belongs to the parent dish; a variant's form must not write a second,
        // conflicting rule under the same dish.
        if ($record->productable_id) {
            return;
        }

        $ids = collect(is_array($values[self::KEY]) ? $values[self::KEY] : [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        // Only branches that exist. A stale option in a form left open while a branch was deleted
        // would otherwise write a row pointing at nothing, and the dish would vanish from every
        // branch at once — exclusive to a place that is gone.
        $ids = $ids->intersect(DB::table('outlets')->pluck('id')->map(fn ($id) => (int) $id))->values();

        DB::table('outlet_product')->where('product_id', $record->id)->delete();

        if ($ids->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('outlet_product')->insert(
            $ids->map(fn (int $outletId) => [
                'outlet_id'  => $outletId,
                'product_id' => $record->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }
}
