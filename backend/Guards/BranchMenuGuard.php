<?php

namespace Theme\Backend\Guards;

use App\Contracts\Storefront\CheckoutGuard;
use Theme\Backend\Models\Outlet;
use Theme\Backend\Support\BranchScope;

/**
 * An order may only contain dishes the branch it is going to actually serves (register O18a).
 *
 * **The defect this closes.** An operator can restrict a branch's menu — *This Outlet Serves
 * Only Selected Dishes*, then a list — and until now nothing read the answer. `outlet_product`
 * recorded it, {@see Outlet::serves()} could answer it, and no caller ever asked. So a customer
 * could put a dish only Bangsar makes in the same basket as a dish only KLCC makes, pay for
 * both, and send one order to one kitchen that could cook half of it. The kitchen found out
 * first, mid-service. Using the feature exactly as documented is what broke the order, which is
 * the worst shape a defect can have.
 *
 * **This is the backstop, not the answer.** The answer is that the branch is chosen before the
 * menu and the menu belongs to the branch, so the impossible basket is never assembled — the
 * phases that follow in the plan. But a basket outlives the choice that made it: it lives in
 * `localStorage`, it survives a branch switch, a reload and a shared link, and `POST
 * /api/storefront/checkout` is a public endpoint that no picker guards. Every ordering platform
 * that scopes its menu also validates at the till, for exactly this reason.
 *
 * **Which branch, and what happens without one.** The branch reaches checkout the way it
 * reaches the kitchen ticket: one pickup-type shipping method per branch, its outlet id written
 * into `meta.checkout_fields` by `ShippingMethodOutlet`. An order naming no branch is **not**
 * refused — a single-outlet shop has no such method and no such field, and refusing it would
 * take down every shop that never used the feature to protect the handful that did.
 *
 * **A branch with no exceptions refuses nothing.** That lives inside {@see Outlet::serves()}
 * rather than here, so a second caller cannot forget it. Since the pivot became an exception list
 * the risk it guards against is much smaller: forgetting the check used to hide a branch's entire
 * menu, whereas now the worst a forgetful caller can do is refuse nothing at all.
 *
 * Fails open, like every guard: the seam's own rule, and the right one. A manifest typo or a
 * half-run migration must not be able to refuse every order on the site. That is survivable here
 * because the menu the customer was shown is the same restriction read from the same pivot — a
 * guard that cannot run leaves the shop exactly where it was before this class existed.
 */
class BranchMenuGuard implements CheckoutGuard
{
    /**
     * How many refused dishes are named before the sentence is summarised.
     *
     * A refusal a customer can act on names the food. A refusal listing eleven dishes is a wall
     * they read as a fault, so past this the count carries the meaning instead.
     */
    private const NAME_LIMIT = 3;

    public function check(array $cart, array $fields, array $context = []): ?string
    {
        $outletId = (int) ($fields['outlet_id'] ?? 0);

        // No branch named. A single-outlet shop is the overwhelming majority of installs and has
        // no outlet to check against — see the class note.
        if ($outletId <= 0) {
            return null;
        }

        $outlet = Outlet::query()->active()->find($outletId);

        // A branch that has been deleted or deactivated since the customer chose it is not this
        // guard's business: `ShippingMethodOutlet` and core's method validation own that, and
        // inventing a second refusal here would give the customer two different reasons for one
        // problem.
        //
        // There is no `restricts_menu` check any more — the pivot became an exception list on
        // 2026-08-25 and `Outlet::serves()` answers on its own. A branch with no exceptions serves
        // everything, so the loop below simply finds nothing to refuse.
        if (! $outlet) {
            return null;
        }

        // A line that chose a size carries the variant's id; `outlet_product` only ever holds the
        // parent's, because the admin picker cannot offer a size. Asking it about a variant gets
        // "serves it" for a dish this branch does not make, which is the whole defect this guard
        // exists to catch, wearing a Large. One query for the basket, before the loop.
        $dishIds = BranchScope::parentIds(
            array_map(fn ($line) => (int) ($line['id'] ?? 0), $cart)
        );

        $unserved = [];

        foreach ($cart as $line) {
            $productId = (int) ($line['id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $dishId = $dishIds[$productId] ?? $productId;

            if ($outlet->serves($dishId)) {
                continue;
            }

            // The dish's own title as the customer read it on the sheet. Falling back to the id
            // would be worse than useless in a refusal — nobody recognises a product id.
            //
            // Keyed by the DISH, so a basket holding two sizes of one unserved dish names it
            // once rather than twice.
            $unserved[$dishId] = trim((string) ($line['title'] ?? '')) ?: __('one of your dishes');
        }

        if ($unserved === []) {
            return null;
        }

        return $this->refusal(array_values($unserved), $outlet);
    }

    /**
     * The sentence the customer reads.
     *
     * It names the branch and the food, because those are the two things they can change. A
     * refusal that says only "some items are unavailable" sends somebody back to a basket to
     * guess which, and guessing wrong costs them a second refusal.
     *
     * @param  array<int,string>  $titles
     */
    private function refusal(array $titles, Outlet $outlet): string
    {
        $branch = $this->branchName($outlet);

        if (count($titles) > self::NAME_LIMIT) {
            return __(':count of your dishes are not served at :branch. Please remove them, or choose another branch.', [
                'count'  => count($titles),
                'branch' => $branch,
            ]);
        }

        // Written as two whole sentences rather than one with a pluralisation rule. `__()` takes
        // a locale as its third argument and not a count — reaching for `trans_choice` here
        // would put a `|` inside a string that already carries `:dishes`, which is a translator
        // trap for the sake of one word.
        if (count($titles) === 1) {
            return __(':dish is not served at :branch. Please remove it, or choose another branch.', [
                'dish'   => $titles[0],
                'branch' => $branch,
            ]);
        }

        return __(':dishes are not served at :branch. Please remove them, or choose another branch.', [
            'dishes' => $this->list($titles),
            'branch' => $branch,
        ]);
    }

    /**
     * "A, B and C" — the shop's own language, not a comma-separated dump.
     *
     * @param  array<int,string>  $titles
     */
    private function list(array $titles): string
    {
        if (count($titles) === 1) {
            return $titles[0];
        }

        $last = array_pop($titles);

        return implode(', ', $titles) . ' ' . __('and') . ' ' . $last;
    }

    /**
     * The branch as the customer saw it named.
     *
     * `title` is translatable, so it arrives as a locale map or a plain string depending on how
     * it was written; the slug is the fallback because a refusal naming an empty branch is a
     * refusal nobody can act on.
     */
    private function branchName(Outlet $outlet): string
    {
        $title = $outlet->title;

        if (is_array($title)) {
            $title = $title[app()->getLocale()] ?? $title['en'] ?? (count($title) ? reset($title) : '');
        }

        return trim((string) $title) ?: (string) $outlet->slug;
    }
}
