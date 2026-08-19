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
        return Outlet::with(['products:id,title'])->findOrFail($id);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $outlet = Outlet::create($this->normalise($data));

            $this->syncProducts($outlet, $data);
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
