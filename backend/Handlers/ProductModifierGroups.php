<?php

namespace Theme\Backend\Handlers;

use App\Contracts\Module\ModuleFieldHandler;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Theme\Backend\Models\ModifierGroup;

/**
 * Stores the modifier groups attached to a dish, from the dish's own form.
 *
 * The pivot was always the right model — a group is authored once and asked about many
 * dishes — but until core grew an extension seam the only screen that could write it was the
 * group's, so attaching ran backwards: you opened "Choose your side" to say which dishes ask
 * it. That is the wrong way round for the job an operator actually does, which is adding a
 * dish and deciding what it asks.
 *
 * Declared by `admin/extends/products.json`, which also declares the one key this may write.
 * Core strips that key before the product row is saved and calls this afterwards, inside the
 * same transaction — so a bad attachment rolls the product back rather than committing a
 * dish whose questions silently vanished.
 */
class ProductModifierGroups implements ModuleFieldHandler
{
    /**
     * The attachments as the repeater renders them, in the order the dish sheet asks.
     *
     * `required_override` is a nullable boolean in the database and a three-state select in
     * the form, so it is stringified here: `''` inherit, `'1'` required, `'0'` optional. Left
     * as a real null/bool it would arrive at a `<select>` whose options are strings and match
     * none of them, and the field would silently show "Inherit" for a dish that overrides.
     */
    public function load(Model $record): array
    {
        if (! $record instanceof Product) {
            return [];
        }

        $rows = $this->attachments($record)
            ->orderByPivot('orders')
            ->orderByPivot('modifier_group_id')
            ->get()
            ->map(fn (ModifierGroup $group) => [
                'modifier_group_id' => $group->id,
                'required_override' => $group->pivot->required_override === null
                    ? ''
                    : (string) (int) $group->pivot->required_override,
            ])
            ->all();

        return ['modifier_groups' => $rows];
    }

    /**
     * Replace this dish's attachments with what the form submitted.
     *
     * A full replace rather than a merge: the repeater posts the complete list, so a row the
     * operator deleted is expressed only by its absence. `sync()` also settles ordering
     * without a second pass, since `orders` is just the row's position.
     *
     * Duplicate groups collapse instead of failing. The pivot's unique index would reject the
     * second row anyway, and rolling back the whole product save because someone picked the
     * same question twice is a worse answer than keeping the first.
     */
    public function save(Model $record, array $values): void
    {
        if (! $record instanceof Product || ! array_key_exists('modifier_groups', $values)) {
            return;
        }

        $rows = $values['modifier_groups'];

        // Null is how an emptied repeater arrives. It means "detach everything", which is a
        // decision the operator made and not a payload to skip.
        $rows = is_array($rows) ? $rows : [];

        $pivot = [];

        foreach (array_values($rows) as $position => $row) {
            $groupId = (int) ($row['modifier_group_id'] ?? 0);

            if ($groupId <= 0 || isset($pivot[$groupId])) {
                continue;
            }

            $override = $row['required_override'] ?? '';

            $pivot[$groupId] = [
                'orders'            => $position,
                'required_override' => ($override === '' || $override === null) ? null : (bool) $override,
            ];
        }

        $this->attachments($record)->sync($pivot);
    }

    /**
     * The pivot, declared here rather than on `Product`.
     *
     * Core's model cannot carry a relation to a table only this theme migrates — the
     * relation would break the moment the theme is switched off, taking any code that
     * eager-loads it with it. Defining it at the point of use keeps the dependency pointing
     * one way: the theme knows about core, and core knows nothing about the theme.
     */
    private function attachments(Product $product): BelongsToMany
    {
        return $product->belongsToMany(
            ModifierGroup::class,
            'dish_modifier_group',
            'product_id',
            'modifier_group_id',
        )->withPivot(['orders', 'required_override'])->withTimestamps();
    }
}
