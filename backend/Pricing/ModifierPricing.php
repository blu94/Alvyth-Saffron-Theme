<?php

namespace Theme\Backend\Pricing;

use App\Contracts\Storefront\CartLinePrice;
use App\Contracts\Storefront\CartLinePricer;
use App\Models\Product;
use Theme\Backend\Models\Modifier;
use Theme\Backend\Models\ModifierGroup;
use Theme\Backend\Support\BranchScope;

/**
 * What a dish's answers cost, and whether they are answers the dish actually offers.
 *
 * Core prices a line from the Product and its variant; it has no idea what "Extra cheese"
 * means because `modifier_groups` is a table this theme's migration creates. So core declares
 * a seam and this class answers it — see `App\Contracts\Storefront\CartLinePricer` for why it
 * is a declared class rather than an event, and `manifest.json` for the declaration itself.
 *
 * ## Fail-open changed severity when this class started setting a price
 *
 * The seam's rule is that a pricer which cannot be constructed, or which throws, is logged and
 * **skipped** — the line keeps its base price, so the shop undercharges rather than refusing a
 * customer. `themes.md` states it and warns in the same breath: *never let a pricer be the only
 * thing between a shop and a loss it cannot absorb.*
 *
 * While this class only added modifier surcharges, the worst case was one line missing one
 * add-on. It now also carries **per-branch pricing**, and that changes what failing open costs:
 * a branch priced above the shop's base means a throwing pricer silently undercharges *every
 * line at that branch, on every order, for as long as it throws* — and the only symptom is
 * revenue, which nobody greps. A missing modifier is visible on a receipt; a whole branch
 * quietly selling at the wrong price is not.
 *
 * It still fails open, because `CartLinePricerRegistry` decides that before any code here runs
 * and a theme cannot opt out of it. What follows from that is a rule for whoever maintains this:
 * **the branch-price lookup must not be able to throw.** It reads one memoised map through
 * {@see BranchScope::prices()}, which catches its own errors and answers `[]`, and
 * {@see self::branchShift()} returns `0.0` the moment that map is empty — so a missing table or
 * an unreadable cookie yields the shop's own prices, which is the same answer a shop without
 * this feature gives. Anything added here that can raise must be given the same treatment, or
 * the failure stops being a skipped surcharge and becomes a pricing outage.
 *
 * Two jobs, deliberately one method, because they are one lookup (register O1 and O7):
 *
 * - **Price.** Each chosen answer adds its `price_delta` to the line's unit price. Before this
 *   existed the delta was rendered on the dish sheet, added to the running total the customer
 *   read, and then collected by nothing — quoted RM 17, billed RM 12, on every order.
 * - **Refuse.** A compulsory question left unanswered, an answer the dish does not offer, or
 *   more answers than the question allows. The dish sheet already enforces all three, but only
 *   in the browser: `CheckoutRequest` has no rule for `cart.*.options`, so anything posting
 *   directly reached the kitchen with no protein chosen.
 *
 * ## The bag this reads
 *
 * `DishSheet` writes one entry per answered question, keyed by the group's **translated
 * title** and valued with the chosen answers' titles joined by `", "` — the strings a cook
 * reads off the ticket and a customer reads on the receipt, which is why they are titles and
 * not slugs:
 *
 *     ['Size' => 'Large', 'Choose your side' => 'Fries', 'Add extras' => 'Cheese, Bacon']
 *
 * Keys that are not one of this dish's groups are **ignored, not refused** — `Size` comes from
 * the variant picker and core itself injects it for a dish with variants, and `Notes` is free
 * text. Refusing unknown keys would break both.
 */
class ModifierPricing implements CartLinePricer
{
    /** How `DishSheet` joins several answers into one option value. */
    private const ANSWER_SEPARATOR = ', ';

    /**
     * The sentence a customer reads when their basket outlived the branch that built it.
     *
     * Names the dish and the branch, because those are the two things they can act on — the same
     * rule {@see \Theme\Backend\Guards\BranchMenuGuard} follows, and worth keeping consistent:
     * a customer who meets both should not be told two different stories about one problem.
     *
     * The branch may be unnameable (a title saved empty, a locale with no translation), in which
     * case the sentence drops the name rather than printing a blank where a place should be.
     */
    private function notServedHere(Product $product): string
    {
        $dish = $product->getTranslation('title', app()->getLocale(), false);
        $dish = trim((string) (is_array($dish) ? reset($dish) : $dish)) ?: __('This dish');

        $branch = BranchScope::name(app()->getLocale());

        if ($branch === '') {
            return __(':dish is not served at the branch you chose. Please remove it, or choose another branch.', [
                'dish' => $dish,
            ]);
        }

        return __(':dish is not served at :branch. Please remove it, or choose another branch.', [
            'dish'   => $dish,
            'branch' => $branch,
        ]);
    }

    /**
     * How much this branch's own price shifts this line, or 0.0 when it has no opinion.
     *
     * The branch prices a **parent dish**, because that is all the admin picker can offer
     * (`ProductRepository::getOptions()` filters `whereNull('productable_id')`), while a line
     * that chose a size carries the **variant's** id. So a variant is resolved back to its
     * parent, the shift is computed from the parent's own price, and the size keeps its premium
     * on top — see {@see BranchScope::priceShift()} for why a replacement would be wrong.
     *
     * The parent row is fetched only for a variant, and only when this branch prices anything
     * at all, so an ordinary shop pays nothing for a feature it does not use.
     */
    private function branchShift(Product $product): float
    {
        $prices = BranchScope::prices();

        if ($prices === []) {
            return 0.0;
        }

        $parentId = (int) ($product->productable_id ?: $product->id);

        if (! array_key_exists($parentId, $prices)) {
            return 0.0;
        }

        $parentBase = $parentId === (int) $product->id
            ? (float) $product->price
            : (float) (Product::query()->whereKey($parentId)->value('price') ?? 0);

        return BranchScope::priceShift($parentId, $parentBase);
    }

    /**
     * The sentence for a dish this branch has run out of tonight.
     *
     * **Deliberately different words from {@see self::notServedHere()}**, because the customer's
     * options differ. "Not served here" invites them to switch branch and the dish comes back;
     * "sold out" means it is gone tonight wherever they look, and telling them to try another
     * branch would send them round the shop for something no branch may have. Naming the branch
     * matters for the first sentence and would be noise in this one.
     */
    private function soldOutHere(Product $product): string
    {
        $dish = $product->getTranslation('title', app()->getLocale(), false);
        $dish = trim((string) (is_array($dish) ? reset($dish) : $dish)) ?: __('This dish');

        return __(':dish has sold out for today. Please remove it to continue.', ['dish' => $dish]);
    }

    /**
     * Groups per dish for this request. `validateCart` calls the pricer once per cart line and
     * a cart of six dishes would otherwise be six of these queries; the pricer is resolved once
     * per request, so an instance cache is enough.
     *
     * @var array<int, array<int, array<string,mixed>>|null>
     */
    private array $groupsByDish = [];

    public function priceOptions(Product $product, array $options): CartLinePrice
    {
        // ── Can the branch being browsed even make this dish? ───────────────────────
        //
        // **Checked before anything is priced, because a dish the branch cannot cook has no
        // price here at all.** This is the layer that actually holds against a cart that
        // outlives the choice which built it: a basket lives in `localStorage`, so it survives a
        // branch switch, a reload, a shared link and a visit a week later. Hiding the dish on
        // the menu does nothing for a line that is already in the basket.
        //
        // `validateCart` is the single chokepoint — the cart page, `POST /storefront/checkout`
        // and the checkout preview all pass through it — so one refusal here closes the total
        // the customer is shown, the order they try to place, and a request typed by hand.
        //
        // It sits in the pricer rather than in a second seam because core takes exactly **one**
        // line pricer per theme, deliberately: two of them returning surcharges for one line
        // could not be combined without silently charging twice. A theme with several rules
        // composes them in its own class, which is what this is.
        //
        // `BranchScope` fails open — no branch, an unreadable cookie, a missing table, and it
        // answers "served". So does this. `BranchMenuGuard` re-checks the whole basket at
        // checkout against the outlet the **order** names rather than the one the browser
        // claims, and that is the backstop behind this one.
        if (! BranchScope::serves($product->id)) {
            return CartLinePrice::refuse($this->notServedHere($product));
        }

        // Served here, but the kitchen ran out tonight. Checked after the exclusivity question
        // and not folded into it: a customer whose dish is 86'd should be told it sold out, not
        // that this branch does not serve it — the second is false and sends them looking for a
        // branch that does.
        if (! BranchScope::inStock($product->id)) {
            return CartLinePrice::refuse($this->soldOutHere($product));
        }

        // What this branch charges, if it charges its own price for this dish.
        //
        // Folded into the same surcharge the modifier answers produce, because core adds ONE
        // number to the line's unit price and that number reaching `OrderItem.unit_price` is
        // what makes the receipt agree with the dish sheet. Computed against `$product->price`
        // — the exact value core used as the base a few lines above — so the result is the
        // branch's price by construction rather than by arithmetic that has to be kept in step.
        $surcharge = $this->branchShift($product);

        $groups = $this->groupsFor($product);

        // No questions on this dish, or the tables are not there. Either way nothing to price
        // and nothing to refuse — the line stands at its base price, shifted by whatever this
        // branch charges. **Returning a bare `allow()` here would drop the branch price for
        // every dish that asks no questions**, which is most of a menu.
        if ($groups === []) {
            return CartLinePrice::allow($surcharge);
        }

        foreach ($groups as $group) {
            $raw = $options[$group['key']] ?? null;
            $raw = is_scalar($raw) ? trim((string) $raw) : '';

            if ($raw === '') {
                // Unanswered. Only a problem when the question was compulsory — and the
                // per-dish override wins over the group's own minimum, because that is what
                // the Modifiers tab on this dish was for.
                if ($group['required']) {
                    return CartLinePrice::refuse(
                        __('Please answer ":question" for :dish.', [
                            'question' => $group['title'],
                            'dish'     => $group['dish_title'],
                        ])
                    );
                }

                continue;
            }

            $chosen = $this->matchAnswers($raw, $group['modifiers']);

            if ($chosen === null) {
                // A label that matches nothing this dish offers. Deliberately not silently
                // dropped: it means the request was not built by this menu, and pricing it as
                // if the answer were free is how a hand-crafted order gets a paid extra for
                // nothing.
                return CartLinePrice::refuse(
                    __('":answer" is not an option for ":question" on :dish.', [
                        'answer'   => $raw,
                        'question' => $group['title'],
                        'dish'     => $group['dish_title'],
                    ])
                );
            }

            $count = count($chosen);

            if ($count < $group['min_select']) {
                return CartLinePrice::refuse(
                    __('Choose at least :n for ":question" on :dish.', [
                        'n'        => $group['min_select'],
                        'question' => $group['title'],
                        'dish'     => $group['dish_title'],
                    ])
                );
            }

            if ($group['max_select'] !== null && $count > $group['max_select']) {
                return CartLinePrice::refuse(
                    __('Choose at most :n for ":question" on :dish.', [
                        'n'        => $group['max_select'],
                        'question' => $group['title'],
                        'dish'     => $group['dish_title'],
                    ])
                );
            }

            foreach ($chosen as $modifier) {
                $surcharge += $modifier['price_delta'];
            }
        }

        return CartLinePrice::allow($surcharge);
    }

    // ── internals ───────────────────────────────────────────────────────────────

    /**
     * Resolve one option value into the modifier rows it names, or null when any part of it
     * names nothing.
     *
     * Answers are joined with ", " on the way out, so they are split on it here. One wrinkle:
     * an answer whose own title contains ", " ("Chips, extra crispy") would split into two
     * tokens that match nothing. Rather than parse, the whole raw value is tried as a single
     * label first — which resolves that case exactly — and only then split. A menu with two
     * answers whose titles overlap across a comma is beyond this and would be refused; the
     * honest fix there is not to name an answer with a comma in it.
     *
     * @param  array<int, array<string,mixed>>  $modifiers
     * @return array<int, array<string,mixed>>|null
     */
    private function matchAnswers(string $raw, array $modifiers): ?array
    {
        $whole = $this->findModifier($raw, $modifiers);

        if ($whole !== null) {
            return [$whole];
        }

        $labels = array_filter(array_map('trim', explode(self::ANSWER_SEPARATOR, $raw)), fn ($l) => $l !== '');

        if ($labels === []) {
            return null;
        }

        $chosen = [];

        foreach ($labels as $label) {
            $modifier = $this->findModifier($label, $modifiers);

            if ($modifier === null) {
                return null;
            }

            $chosen[] = $modifier;
        }

        return $chosen;
    }

    /**
     * A modifier whose title matches this label in **any** locale.
     *
     * Any, not the current one: the label was written into the cart in whatever locale the
     * customer was browsing, and a cart survives a language switch in the header. Matching
     * only the request's locale would refuse a line the customer legitimately built.
     *
     * @param  array<int, array<string,mixed>>  $modifiers
     * @return array<string,mixed>|null
     */
    private function findModifier(string $label, array $modifiers): ?array
    {
        $needle = $this->fold($label);

        foreach ($modifiers as $modifier) {
            foreach ($modifier['labels'] as $candidate) {
                if ($this->fold($candidate) === $needle) {
                    return $modifier;
                }
            }
        }

        return null;
    }

    /** Case- and whitespace-insensitive, so a stray double space cannot cost a customer a sale. */
    private function fold(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /**
     * This dish's active questions, in the order it asks them.
     *
     * The same query `DishSheet::resolveGroups()` runs, and the same degrade: these tables ship
     * with this theme's migrations, so a checkout running before they have been applied must
     * lose modifier pricing rather than refuse every line in the shop. That is the fail-open
     * direction core's registry documents, arrived at here as well rather than relied on.
     *
     * @return array<int, array<string,mixed>>
     */
    private function groupsFor(Product $product): array
    {
        if (array_key_exists($product->id, $this->groupsByDish)) {
            return $this->groupsByDish[$product->id] ?? [];
        }

        try {
            $groups = ModifierGroup::query()
                ->where('modifier_groups.status', 'active')
                ->with(['modifiers' => fn ($q) => $q->where('status', 'active')])
                ->join('dish_modifier_group', 'dish_modifier_group.modifier_group_id', '=', 'modifier_groups.id')
                ->where('dish_modifier_group.product_id', $product->id)
                ->orderBy('dish_modifier_group.orders')
                ->select('modifier_groups.*', 'dish_modifier_group.required_override as pivot_required_override')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return $this->groupsByDish[$product->id] = [];
        }

        $locale    = app()->getLocale();
        $dishTitle = $this->translate($product->title, $locale) ?: (string) $product->sku;

        return $this->groupsByDish[$product->id] = $groups->map(function (ModifierGroup $group) use ($locale, $dishTitle) {
            $override = $group->pivot_required_override;
            $override = $override === null ? null : (bool) $override;

            $required = $group->isRequired($override);

            return [
                // The bag's key is the group's translated title — DishSheet writes it that way
                // because the string ends up on the kitchen ticket and the customer's receipt.
                'key'        => $this->translate($group->title, $locale) ?: $group->slug,
                'title'      => $this->translate($group->title, $locale) ?: $group->slug,
                'dish_title' => $dishTitle,
                'required'   => $required,
                'min_select' => $required ? max(1, (int) $group->min_select) : 0,
                'max_select' => $group->effectiveMaxSelect(),
                'modifiers'  => $group->modifiers->map(fn (Modifier $m) => [
                    'price_delta' => (float) $m->price_delta,
                    'labels'      => $this->labelsOf($m),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Every locale's spelling of one answer, plus whatever a non-translatable value holds.
     *
     * @return array<int,string>
     */
    private function labelsOf(Modifier $modifier): array
    {
        $raw = $modifier->getRawOriginal('title');

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [$raw];
        }

        $labels = is_array($raw) ? array_values($raw) : [];

        return array_values(array_filter(
            array_map(fn ($l) => is_scalar($l) ? trim((string) $l) : '', $labels),
            fn ($l) => $l !== ''
        ));
    }

    /** Resolve a translatable attribute that may arrive as a raw string or a locale map. */
    private function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
