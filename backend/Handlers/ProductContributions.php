<?php

namespace Theme\Backend\Handlers;

use App\Contracts\Module\ModuleFieldHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything this theme contributes to core's product form, behind one handler.
 *
 * **Why a composite rather than two contributions.** Core's extension seam is one JSON file per
 * module type and one `handler` inside it, so a theme adding a second tab to a form it already
 * extends has nowhere to declare a second class. The alternatives were both worse: fold the
 * branch-availability logic into `ProductModifierGroups`, which would leave a class named for
 * questions writing a catalogue rule; or ship a second `extends/products.json`, which the registry
 * would refuse as a duplicate. So the seam gets one class and the two real behaviours stay in
 * their own files, each testable on its own.
 *
 * Order is not significant — the two own disjoint keys and touch different tables — but they run
 * inside `ProductController`'s transaction either way, so a refusal from the second rolls the
 * first back with the product.
 *
 * Each delegate guards on `array_key_exists` for its own key, so a save that carries only one tab
 * leaves the other's data alone rather than clearing it.
 */
class ProductContributions implements ModuleFieldHandler
{
    public function load(Model $record): array
    {
        return array_merge(
            (new ProductModifierGroups())->load($record),
            (new ProductBranchExclusivity())->load($record),
        );
    }

    public function save(Model $record, array $values): void
    {
        (new ProductModifierGroups())->save($record, $values);
        (new ProductBranchExclusivity())->save($record, $values);
    }
}
