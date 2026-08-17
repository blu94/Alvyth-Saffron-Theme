<?php

namespace Theme\Backend\Repositories;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
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
 *
 * **Attaching in bulk is back, on the other side of that line.** Fifty dishes meant fifty
 * product forms, so this module also serves an **Attach To Dishes** page — but it appends a
 * question one past whatever that dish's last question sits at, exactly where the dish's own
 * Modifiers tab would have drawn a new last row, and it never touches a dish that already asks
 * it. So there is still one writer of what `orders` *means*: the dish. See
 * {@see self::applyAttachment()}.
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
        $group = ModifierGroup::with(['modifiers', 'dishes'])->find($id);

        // `dishes_summary` is the form's read-only reach list. Appended here rather than on the
        // model's `$appends` because the index serialises every row and would resolve the
        // relation once per row for a figure `withCount` already supplies.
        return $group?->append('dishes_summary');
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
        $options = [];

        // A schema asks for a column with `params: {"columns[]": "…"}`; a single value can
        // arrive as a bare string, and iterating a string is a TypeError, not an empty list.
        foreach ((array) $columns as $column) {
            $options[$column] = match ($column) {
                'status', 'selection' => ModifierGroup::query()
                    ->whereNotNull($column)
                    ->distinct()
                    ->pluck($column)
                    ->map(fn ($value) => ['label' => Str::headline((string) $value), 'value' => $value])
                    ->values()
                    ->all(),

                // Every question, for the Attach To Dishes picker. Not a distinct column
                // lookup like the two above — it is the list of records themselves, the same
                // shape the Kitchen Queue's `open_order` uses.
                'group' => ModifierGroup::query()
                    ->orderBy('orders')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (ModifierGroup $group) => [
                        'label' => sprintf(
                            '%s (%s)',
                            $group->getTranslation('title', app()->getLocale(), false)
                                ?: $group->getTranslation('title', 'en', false)
                                ?: $group->slug,
                            $group->selection === 'single' ? 'single' : 'multiple'
                        ),
                        'value' => (string) $group->id,
                    ])
                    ->values()
                    ->all(),

                default => [],
            };
        }

        return $options;
    }

    // ── Custom admin pages ──────────────────────────────────────────────────────

    public function pageData(string $slug)
    {
        return match ($slug) {
            'attach' => $this->attachPageData(),
            default  => [],
        };
    }

    public function savePageData(string $slug, array $data)
    {
        return match ($slug) {
            'attach' => $this->applyAttachment($data),
            default  => ['message' => 'No save handler defined for this page.'],
        };
    }

    /**
     * The Attach To Dishes screen as it first loads: the form's defaults, plus the reach.
     */
    protected function attachPageData(): array
    {
        return $this->attachSummary() + [
            'apply_mode'        => 'attach',
            'required_override' => '',
            'last_result'       => 'Nothing applied yet.',
        ];
    }

    /**
     * The read-only half of the Attach screen — which questions reach how many dishes, and
     * which dishes ask nothing at all.
     *
     * Returned again after every apply, and deliberately WITHOUT the form's own keys: the page
     * component merges a message-less response into its model, so returning `apply_mode` here
     * would flip the operator's Detach choice back to Attach the moment they used it.
     *
     * @return array<string, mixed>
     */
    protected function attachSummary(): array
    {
        $groups = ModifierGroup::query()
            ->withCount('dishes')
            ->orderBy('orders')
            ->orderBy('id')
            ->get();

        // Active parent dishes with no question attached. Variants are excluded because a
        // group is attached to the parent and every variant inherits it — listing a variant
        // here would report a gap that cannot be filled.
        $unasked = Product::query()
            ->where('status', 'active')
            ->whereNull('productable_id')
            ->whereNotIn('id', function ($query) {
                $query->select('product_id')->from('dish_modifier_group');
            })
            ->orderBy('id')
            ->get(['id', 'title', 'sku']);

        return [
            'total_groups'   => (string) $groups->count(),
            'unasked_count'  => (string) $unasked->count(),
            'groups_summary' => $groups
                ->map(fn (ModifierGroup $group) => sprintf(
                    '%s — %s',
                    $group->getTranslation('title', app()->getLocale(), false)
                        ?: $group->getTranslation('title', 'en', false)
                        ?: $group->slug,
                    $group->dishes_count === 1 ? '1 dish' : $group->dishes_count . ' dishes'
                ))
                ->values()
                ->all(),
            'unasked_dishes' => $this->capped(
                $unasked->map(fn (Product $dish) => $this->dishTitle($dish))->values()->all()
            ),
        ];
    }

    /**
     * Attach or detach one question across many dishes in a single action.
     *
     * **The dish keeps owning `orders`.** A new attachment is appended one past whatever that
     * dish's last question sits at, which is exactly where the dish's own Modifiers tab would
     * have drawn a new last row — so the two screens cannot disagree about the order the dish
     * sheet asks its questions in. Nothing here renumbers a dish's existing rows, and a dish
     * that already asks the question is left **untouched**: its `required_override` is a
     * per-dish decision somebody made on that dish's form, and a bulk tool that quietly
     * overwrote it would be the second writer this shape exists to avoid.
     *
     * Detaching leaves gaps in `orders` (2, 4, 5). They are invisible: the tab reads by
     * `orders` then id, and the next save of that dish renumbers from zero.
     *
     * Bad input throws rather than returning a message. `GenericModuleController::savePageData`
     * turns a message into a **green** toast whatever it says, so "Pick a question first"
     * would read as a success; a throw rolls the transaction back and shows it in red.
     *
     * @return array<string, mixed>
     */
    protected function applyAttachment(array $data): array
    {
        $groupId  = (int) ($data['group_id'] ?? 0);
        $mode     = ($data['apply_mode'] ?? 'attach') === 'detach' ? 'detach' : 'attach';
        $override = $data['required_override'] ?? '';

        $requested = array_values(array_unique(array_filter(
            array_map('intval', (array) ($data['dish_ids'] ?? [])),
            fn (int $id) => $id > 0
        )));

        if ($groupId <= 0) {
            throw new RuntimeException('Pick a question first.');
        }

        if ($requested === []) {
            throw new RuntimeException('Pick at least one dish.');
        }

        $group = ModifierGroup::find($groupId);

        if (! $group) {
            throw new RuntimeException('That question no longer exists.');
        }

        // Re-resolved server-side rather than trusted: the picker only ever offers parent
        // dishes, but this endpoint takes whatever is posted to it.
        $dishIds = Product::query()
            ->whereIn('id', $requested)
            ->whereNull('productable_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ignored = count($requested) - count($dishIds);

        if ($dishIds === []) {
            throw new RuntimeException('None of those are dishes a question can be attached to.');
        }

        $title = $group->getTranslation('title', app()->getLocale(), false)
            ?: $group->getTranslation('title', 'en', false)
            ?: $group->slug;

        $result = DB::transaction(fn () => $mode === 'detach'
            ? $this->detachFromDishes($group, $dishIds, $title)
            : $this->attachToDishes($group, $dishIds, $override, $title));

        if ($ignored > 0) {
            $result .= sprintf(
                ' %d of the ids sent were not attachable dishes — a variant, or one since deleted — and were ignored.',
                $ignored
            );
        }

        return $this->attachSummary() + ['last_result' => $result];
    }

    /**
     * @param  array<int, int>  $dishIds
     */
    protected function attachToDishes(ModifierGroup $group, array $dishIds, mixed $override, string $title): string
    {
        $already = DB::table('dish_modifier_group')
            ->where('modifier_group_id', $group->id)
            ->whereIn('product_id', $dishIds)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $fresh = array_values(array_diff($dishIds, $already));

        if ($fresh !== []) {
            // Where each of those dishes currently ends. A dish with no questions is absent
            // from the result and starts at 0; a dish whose rows carry a null `orders` reads
            // as 0 here and the new row lands after it, which is also where it sorts.
            $tail = [];

            foreach (
                DB::table('dish_modifier_group')
                    ->selectRaw('product_id, MAX(orders) as max_orders')
                    ->whereIn('product_id', $fresh)
                    ->groupBy('product_id')
                    ->get() as $row
            ) {
                $tail[(int) $row->product_id] = (int) $row->max_orders;
            }

            $required = ($override === '' || $override === null) ? null : (bool) $override;

            $payload = [];

            foreach ($fresh as $dishId) {
                $payload[$dishId] = [
                    'orders'            => array_key_exists($dishId, $tail) ? $tail[$dishId] + 1 : 0,
                    'required_override' => $required,
                ];
            }

            $group->dishes()->attach($payload);
        }

        $sentence = sprintf(
            'Attached "%s" to %s.',
            $title,
            count($fresh) === 1 ? '1 dish' : count($fresh) . ' dishes'
        );

        if ($already !== []) {
            // Written as two whole sentences rather than one with swappable fragments. The
            // fragment version produced "1 dish already asked it and was left exactly as it
            // were" — the verb was pluralised in one place and not the other, which is the
            // failure mode any sentence assembled from parts eventually has.
            $sentence .= count($already) === 1
                ? ' 1 dish already asked it and was left exactly as it was.'
                : sprintf(' %d dishes already asked it and were left exactly as they were.', count($already));
        }

        return $sentence;
    }

    /**
     * @param  array<int, int>  $dishIds
     */
    protected function detachFromDishes(ModifierGroup $group, array $dishIds, string $title): string
    {
        $removed = $group->dishes()->detach($dishIds);
        $missing = count($dishIds) - $removed;

        $sentence = sprintf(
            'Removed "%s" from %s.',
            $title,
            $removed === 1 ? '1 dish' : $removed . ' dishes'
        );

        if ($missing > 0) {
            $sentence .= sprintf(' %s did not ask it.', $missing === 1 ? '1 dish' : $missing . ' dishes');
        }

        return $sentence;
    }

    /**
     * A dish's name in the admin's locale, falling back to English and then to its SKU.
     */
    protected function dishTitle(Product $dish): string
    {
        return (string) ($dish->getTranslation('title', app()->getLocale(), false)
            ?: $dish->getTranslation('title', 'en', false)
            ?: $dish->sku
            ?: ('Dish #' . $dish->id));
    }

    /**
     * A chip list that stops before it becomes a wall, with the remainder named.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    protected function capped(array $values): array
    {
        if (count($values) <= ModifierGroup::SUMMARY_LIMIT) {
            return $values;
        }

        $extra = count($values) - ModifierGroup::SUMMARY_LIMIT;

        return array_merge(
            array_slice($values, 0, ModifierGroup::SUMMARY_LIMIT),
            [sprintf('… and %d more', $extra)]
        );
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
