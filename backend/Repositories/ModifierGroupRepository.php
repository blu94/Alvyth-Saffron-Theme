<?php

namespace Theme\Backend\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Theme\Backend\Models\Modifier;
use Theme\Backend\Models\ModifierGroup;

/**
 * Resolved by `GenericModuleController` for the `modifier-groups` module.
 *
 * The modifiers themselves arrive as a repeater on the group form, so create/update own the
 * whole aggregate: the group row plus its answers, in one transaction.
 *
 * **This screen no longer attaches groups to dishes.** It used to, through a `dish_ids`
 * picker, because the product form could not be extended and the pivot had to be written from
 * somewhere. That direction ran backwards from the job — you added a dish and then went
 * looking for every question to edit — and once
 * {@see \Theme\Backend\Handlers\ProductModifierGroups} could write the pivot from the dish,
 * keeping both made two writers for one `orders` column with incompatible meanings: the
 * group's position on the dish, and the dish's position in the group. Saving either screen
 * would have silently reordered the other's. The dish owns the attachment; this screen
 * authors the reusable question. `dishes()` remains for the attachment count on the list.
 */
class ModifierGroupRepository
{
    public function baseIndexQuery(array $filters = [])
    {
        $query = ModifierGroup::query()->with('modifiers')->withCount(['modifiers', 'dishes']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['selection'])) {
            $query->where('selection', $filters['selection']);
        }

        return $query;
    }

    public function find($id)
    {
        return ModifierGroup::with(['modifiers', 'dishes'])->find($id);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $modifiers = $this->extractModifiers($data);

            $group = ModifierGroup::create($this->normalise($data));

            $this->writeModifiers($group, $modifiers);

            return $group->load(['modifiers', 'dishes']);
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $group     = ModifierGroup::findOrFail($id);
            $hasMods   = array_key_exists('modifiers', $data);
            $modifiers = $this->extractModifiers($data);

            $group->update($this->normalise($data, $group));

            // Only rewrite the children the request actually carried. A partial update that
            // omits `modifiers` must not be read as "delete every answer".
            if ($hasMods) {
                $this->writeModifiers($group, $modifiers);
            }

            return $group->load(['modifiers', 'dishes']);
        });
    }

    public function delete($id)
    {
        // Soft delete. The modifiers' FK is cascade-on-delete, which only fires on a hard
        // delete, so the answers stay with the group and come back with it on restore.
        return ModifierGroup::findOrFail($id)->delete();
    }

    public function getOptions(array $columns = [])
    {
        $valid   = ['status', 'selection'];
        $columns = array_intersect($columns, $valid);
        $options = [];

        foreach ($columns as $column) {
            $options[$column] = ModifierGroup::query()
                ->whereNotNull($column)
                ->distinct()
                ->pluck($column)
                ->map(fn ($value) => ['label' => Str::headline((string) $value), 'value' => $value])
                ->values()
                ->all();
        }

        return $options;
    }

    // ── internals ───────────────────────────────────────────────────────────────

    /**
     * Keep the group's own columns and coerce the two numeric limits.
     *
     * `max_select` is meaningless for a single-selection group and is stored as null there
     * rather than left to contradict the radio list the storefront will render.
     */
    protected function normalise(array $data, ?ModifierGroup $existing = null): array
    {
        $out = collect($data)
            ->only(['title', 'slug', 'description', 'selection', 'min_select', 'max_select', 'status', 'orders', 'data'])
            ->all();

        if (array_key_exists('min_select', $out)) {
            $out['min_select'] = max(0, (int) $out['min_select']);
        }

        $selection = $out['selection'] ?? $existing?->selection ?? 'single';

        if ($selection === 'single') {
            $out['max_select'] = null;
        } elseif (array_key_exists('max_select', $out)) {
            $max = $out['max_select'];
            $out['max_select'] = ($max === null || $max === '' || (int) $max < 1) ? null : (int) $max;
        }

        return $out;
    }

    protected function extractModifiers(array &$data): array
    {
        $modifiers = $data['modifiers'] ?? [];
        unset($data['modifiers']);

        return is_array($modifiers) ? $modifiers : [];
    }

    /**
     * Replace the group's answers with what the form submitted.
     *
     * Rows carrying an `id` are updated in place so their primary keys survive — an order
     * line's options are a snapshot of the *title*, but a stable id keeps the admin's own
     * links and activity log coherent across edits.
     */
    protected function writeModifiers(ModifierGroup $group, array $modifiers): void
    {
        $keptIds = [];

        foreach (array_values($modifiers) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            // A blank row must be dropped, and "blank" includes a locale map whose every
            // translation is empty — which is exactly what the repeater submits for a row the
            // operator opened and abandoned. Checking only for '' or [] let those through.
            if (!$this->hasAnyTranslation($row['title'] ?? null)) {
                continue;
            }

            $title = $row['title'];

            $payload = [
                'title'       => $title,
                'price_delta' => isset($row['price_delta']) ? (float) $row['price_delta'] : 0,
                'product_id'  => !empty($row['product_id']) ? (int) $row['product_id'] : null,
                'is_default'  => (bool) ($row['is_default'] ?? false),
                'status'      => $row['status'] ?? 'active',
                'orders'      => isset($row['orders']) ? (int) $row['orders'] : $index,
            ];

            $existingId = !empty($row['id']) ? (int) $row['id'] : null;
            $modifier   = $existingId ? $group->modifiers()->whereKey($existingId)->first() : null;

            if ($modifier) {
                $modifier->update($payload);
            } else {
                $modifier = $group->modifiers()->create($payload);
            }

            $keptIds[] = $modifier->id;
        }

        // A single-selection group can pre-select at most one answer. Enforced here rather
        // than in the browser, because the dish sheet reads is_default straight from the row.
        if ($group->selection === 'single') {
            $defaults = $group->modifiers()->whereKey($keptIds)->where('is_default', true)
                ->orderBy('orders')->orderBy('id')->pluck('id');

            if ($defaults->count() > 1) {
                $group->modifiers()->whereKey($defaults->slice(1)->all())->update(['is_default' => false]);
            }
        }

        $group->modifiers()->whereKeyNot($keptIds)->delete();
    }

    /**
     * Whether a translatable value carries any real text in any locale.
     *
     * The repeater submits `['en' => '']` for an abandoned row, which is neither null, '' nor
     * [] — so the naive emptiness checks all pass it and an untitled answer reaches the menu.
     */
    protected function hasAnyTranslation(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $translation) {
                if (is_scalar($translation) && trim((string) $translation) !== '') {
                    return true;
                }
            }

            return false;
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }

}
