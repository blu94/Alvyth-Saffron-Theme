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
        return Outlet::with(['products:id,title', 'tables' => fn ($q) => $q->ordered()])
            ->findOrFail($id);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $outlet = Outlet::create($this->normalise($data));

            $this->syncProducts($outlet, $data);
            $this->writeTables($outlet, $data);
            $this->enforceSingleDefault($outlet);

            return $outlet->fresh();
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $outlet = Outlet::findOrFail($id);
            $outlet->update($this->normalise($data, $outlet));

            // Only when the form actually sent the key. A partial update — the status toggle on
            // the index row, for instance — must not read a missing key as "serve nothing", which
            // is precisely how a repeater-backed relation gets wiped by a screen that never
            // showed it.
            if (array_key_exists('product_ids', $data)) {
                $this->syncProducts($outlet, $data);
            }

            // Same guard, same reason: a partial update that never showed the dining room must
            // not be read as "this branch has no tables".
            if (array_key_exists('tables', $data)) {
                $this->writeTables($outlet, $data);
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
        $out = collect($data)->only([
            'title', 'slug', 'address', 'phone', 'latitude', 'longitude',
            'offers_pickup', 'offers_delivery', 'restricts_menu', 'is_default',
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

    protected function syncProducts(Outlet $outlet, array $data): void
    {
        $ids = collect($data['product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $outlet->products()->sync($ids);
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
