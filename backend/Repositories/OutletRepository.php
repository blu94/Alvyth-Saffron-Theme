<?php

namespace Theme\Backend\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Theme\Backend\Models\Outlet;

/**
 * The Outlets screen's server side.
 *
 * Same contract every theme-shipped module uses: `baseIndexQuery`, `find`, `create`, `update`,
 * `delete`, `getOptions`. Core's `GenericModuleController` calls these; nothing here is reached
 * from the storefront.
 */
class OutletRepository
{
    public function baseIndexQuery(array $filters = [])
    {
        $query = Outlet::query()->ordered();

        // Both filters are hardened to scalars before use. The admin list is a DataTable, and
        // DataTables sends `search` as an OBJECT (`{value, regex}`) rather than a string — casting
        // that with `(string)` threw "Array to string conversion" and 500'd the whole Outlets
        // list. `status` gets the same treatment because a multi-select filter would arrive as an
        // array for exactly the same reason.
        $status = $filters['status'] ?? null;

        if (is_scalar($status) && (string) $status !== '') {
            $query->where('status', (string) $status);
        }

        $search = $filters['search'] ?? null;

        // DataTables' shape, unwrapped rather than rejected: the value is what the operator typed.
        if (is_array($search)) {
            $search = $search['value'] ?? null;
        }

        if (is_scalar($search) && trim((string) $search) !== '') {
            $term = trim((string) $search);
            $query->where(function ($q) use ($term) {
                $q->where('slug', 'like', "%{$term}%")
                    ->orWhere('address', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    // Titles are locale-keyed JSON, so a LIKE over the column is the only search
                    // that finds a branch by name in any locale without knowing which.
                    ->orWhere('title', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    public function find($id)
    {
        // `tables` is eager-loaded in the operator's own order so the repeater draws the room
        // the way it is walked, not the way the ids happen to fall.
        $outlet = Outlet::with(['exclusiveProducts:id,title', 'tables' => fn ($q) => $q->ordered()])
            ->findOrFail($id);

        // **Read-only, and the branch no longer owns this.** Exclusivity is a property of the
        // DISH — "this dish is only made at KLCC" — so it is authored on the product form, where
        // it is one statement instead of one per branch. What the outlet screen can usefully show
        // is the consequence: which dishes are exclusive to this branch. There is no write path
        // here on purpose, because two screens writing one pivot with different ideas of what a
        // row means is precisely how this feature went wrong twice.
        //
        // Set here rather than as a model `$appends`, because the index listing loads outlets too
        // and must not pay for a relation no list column shows.
        $outlet->setAttribute(
            'exclusive_product_ids',
            $outlet->exclusiveProducts->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
        );

        // **The names, for a `display` field rather than a picker.**
        //
        // The first attempt at showing this read-only used an autocomplete with `ui.readonly` —
        // and `readonly` is not bound on that control, so the field rendered fully editable and an
        // operator could pick a dish and watch the save discard it. That is precisely the defect
        // this section was rewritten to remove, reintroduced by the fix for it. Measured in a
        // browser: `input.readOnly === false`, no attribute. A `display` field has no input at all,
        // so it cannot be edited by anybody however the engine evolves.
        //
        // Titles are translatable JSON and are resolved here rather than in the schema, for the
        // reason `ModifierGroup::dishes_summary` resolves its own: a form is presentation and has
        // no locale to resolve against.
        // **What this branch has run out of tonight — and unlike the list above, the branch
        // owns this and writes it here.** The two sit on one screen and mean opposite things,
        // so the distinction is worth stating where somebody is editing them: exclusivity is a
        // catalogue fact about the DISH ("only we make this"), read-only here because the dish
        // owns it; 86'ing is a service fact about the BRANCH ("we ran out"), authored here
        // because nobody else can know it. A picker rather than a display, for that reason.
        $outlet->setAttribute(
            'unavailable_product_ids',
            DB::table('outlet_product_unavailable')
                ->where('outlet_id', $outlet->id)
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()
        );

        $outlet->setAttribute(
            'exclusive_products_summary',
            $outlet->exclusiveProducts->map(function ($product) {
                $title = $product->title;

                if (is_array($title)) {
                    $title = $title[app()->getLocale()] ?? $title['en'] ?? (count($title) ? reset($title) : '');
                }

                return trim((string) $title);
            })->filter()->values()->all()
        );

        return $outlet;
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $outlet = Outlet::create($this->normalise($data));

            $this->writeTables($outlet, $data);
            $this->writeSoldOut($outlet, $data);
            $this->enforceSingleDefault($outlet);

            return $outlet->fresh();
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $outlet = Outlet::findOrFail($id);
            $outlet->update($this->normalise($data, $outlet));

            // Same guard, same reason: a partial update that never showed the dining room must
            // not be read as "this branch has no tables".
            if (array_key_exists('tables', $data)) {
                $this->writeTables($outlet, $data);
            }

            // Same guard again, and here it is load-bearing in the other direction: a partial
            // save that never rendered the 86 list must not be read as "everything is back on".
            // Putting a whole menu back because somebody toggled Status from the index row is
            // the failure this prevents.
            if (array_key_exists('unavailable_product_ids', $data)) {
                $this->writeSoldOut($outlet, $data);
            }

            $this->enforceSingleDefault($outlet);

            return $outlet->fresh();
        });
    }

    public function delete($id)
    {
        $outlet = Outlet::findOrFail($id);

        // Soft delete, so an outlet that has taken orders keeps resolving on those orders. The
        // pivot is left alone deliberately: restoring the branch should restore its menu.
        $outlet->delete();

        return true;
    }

    public function getOptions(array $columns = [])
    {
        return [
            'status' => [
                ['title' => 'Active', 'value' => 'active'],
                ['title' => 'Inactive', 'value' => 'inactive'],
            ],
            // The picker on the form, and the same list the storefront's outlet chooser needs.
            'outlets' => Outlet::active()->ordered()->get()->map(fn (Outlet $o) => [
                'title' => $o->getTranslation('title', app()->getLocale(), false) ?: $o->slug,
                'value' => $o->id,
            ])->all(),
        ];
    }

    /**
     * A slug is generated from the name when the operator leaves it empty, and kept unique.
     */
    protected function normalise(array $data, ?Outlet $existing = null): array
    {
        // An allow-list, so a hand-crafted POST cannot write a column the form does not show.
        // The cost of that shape is that a NEW column is silently dropped until it is added here
        // — the switch saves, the form redraws it off, and nothing anywhere reports a failure.
        // `product_ids` was invisible for the opposite reason and it is the same lesson: when a
        // field key and a column are two lists, adding to one is only ever half the change.
        $out = collect($data)->only([
            'title', 'slug', 'address', 'phone', 'latitude', 'longitude',
            'offers_pickup', 'offers_delivery', 'offers_dine_in', 'is_default',
            'timezone', 'status', 'orders',
        ])->all();

        $title = $out['title'] ?? [];
        $name  = is_array($title) ? ($title[app()->getLocale()] ?? reset($title) ?: '') : (string) $title;

        if (empty($out['slug']) && $name !== '') {
            $out['slug'] = Str::slug($name);
        }

        if (! empty($out['slug'])) {
            $base = Str::slug($out['slug']);
            $slug = $base;
            $n = 2;

            while (Outlet::withTrashed()
                ->where('slug', $slug)
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->exists()) {
                $slug = $base . '-' . $n++;
            }

            $out['slug'] = $slug;
        }

        return $out;
    }

    /**
     * Replace this branch's tables with what the dining-room repeater submitted.
     *
     * Rows carrying an `id` are updated in place so their keys survive an edit — the same rule
     * the modifier repeater follows, and it matters more here: phase 2's bookings will point at
     * a table id, and a room that renumbered itself on every save would move a booking to a
     * different table.
     *
     * Three rules, each enforced here rather than in the browser, because the storefront reads
     * these rows directly and a hand-crafted request meets the same schema:
     *
     * - **A row with no label is dropped.** The repeater submits an empty row for one the
     *   operator opened and abandoned, and an unnamed table would reach the customer's list as
     *   a blank line they could select.
     * - **A duplicate label within one branch is dropped**, keeping the first. Two tables named
     *   7 in one room is an operator slip whose symptom is a plate carried to the wrong party,
     *   and it cannot be caught downstream — the ticket prints a name, not an id. It is not a
     *   database constraint because these rows soft-delete, and a unique index would let a
     *   deleted "7" block the operator from ever creating "7" again.
     * - **A table the form no longer lists is deleted**, softly, so an order that already named
     *   it still resolves.
     */
    /**
     * The dishes this branch has 86'd, replaced wholesale from the form's picker.
     *
     * A full replace rather than a merge, like the Modifiers repeater: the control posts the
     * complete list, so a dish the operator un-ticked is expressed only by its absence, and an
     * emptied picker puts the whole menu back on — which is exactly what "we restocked" means
     * and must not require clearing rows one at a time at the end of service.
     *
     * Ids are intersected with real products before they are written. A stale option in a form
     * left open while a dish was deleted would otherwise write a row pointing at nothing, and
     * `BranchSoldOutGuard` would then refuse an order over a dish whose name it cannot even
     * print.
     *
     * The rows carry no reason and no expiry, and both absences are deliberate — see the
     * migration. A dish comes back when somebody says so.
     */
    protected function writeSoldOut(Outlet $outlet, array $data): void
    {
        $ids = collect(is_array($data['unavailable_product_ids'] ?? null) ? $data['unavailable_product_ids'] : [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $ids = $ids->intersect(
            DB::table('products')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)
        )->values();

        DB::table('outlet_product_unavailable')->where('outlet_id', $outlet->id)->delete();

        if ($ids->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('outlet_product_unavailable')->insert(
            $ids->map(fn (int $productId) => [
                'outlet_id'  => $outlet->id,
                'product_id' => $productId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    protected function writeTables(Outlet $outlet, array $data): void
    {
        $rows = $data['tables'] ?? [];

        if (! is_array($rows)) {
            return;
        }

        $keptIds = [];
        $seen    = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            // Case-insensitively, because "table 7" and "Table 7" are one table to everybody
            // except a string comparison.
            $key = mb_strtolower($label);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $seats = $row['seats'] ?? null;

            $payload = [
                'label'  => $label,
                'seats'  => ($seats === null || $seats === '') ? null : max(1, (int) $seats),
                'status' => $row['status'] ?? 'active',
                'orders' => isset($row['orders']) && $row['orders'] !== '' ? (int) $row['orders'] : $index,
            ];

            $existingId = ! empty($row['id']) ? (int) $row['id'] : null;
            $table      = $existingId ? $outlet->tables()->whereKey($existingId)->first() : null;

            if ($table) {
                $table->update($payload);
            } else {
                $table = $outlet->tables()->create($payload);
            }

            $keptIds[] = $table->id;
        }

        $outlet->tables()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    /**
     * Exactly one outlet is the default.
     *
     * Enforced here rather than by a unique index, because a unique index on a boolean would
     * forbid the second `false` as well as the second `true`.
     */
    protected function enforceSingleDefault(Outlet $outlet): void
    {
        if (! $outlet->is_default) {
            // A shop must always have a default once it has any outlet at all, or the storefront
            // has nothing to fall back on for a customer who has not chosen.
            if (! Outlet::where('is_default', true)->exists()) {
                Outlet::whereKey($outlet->id)->update(['is_default' => true]);
            }

            return;
        }

        Outlet::whereKeyNot($outlet->id)->where('is_default', true)->update(['is_default' => false]);
    }
}
