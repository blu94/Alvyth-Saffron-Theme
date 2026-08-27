<?php

namespace Theme\Backend\Guards;

use App\Contracts\Storefront\CheckoutGuard;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Theme\Backend\Models\Outlet;

/**
 * Refuses an order containing a dish the branch has 86'd — run out of tonight.
 *
 * **The backstop to `ModifierPricing`, and it exists for the same reason `BranchMenuGuard`
 * does.** The pricer refuses at the cart, judging the branch the *browser* is scoped to; this
 * judges the branch the **order** names, because a basket outlives the choice that made it. It
 * lives in `localStorage`, it survives a branch switch and a shared link, and
 * `POST /api/storefront/checkout` is public and guarded by no picker.
 *
 * **Why a separate guard from `BranchMenuGuard` rather than another loop inside it.** They
 * answer different questions and owe the customer different sentences. That one says the branch
 * does not serve a dish — a catalogue fact, and the customer's move is to switch branch. This
 * says the kitchen has run out tonight — a service fact, and switching branch is exactly the
 * wrong advice, because it sends them round the shop after something the next branch may also be
 * out of. Folding the two would force one sentence to cover both, and the wrong half of it would
 * be a lie.
 *
 * **It fails open, and here that is a decision rather than an inheritance — read this before
 * copying the pattern.** Every other guard in this seam expresses a commercial preference:
 * opening hours, a minimum spend, a branch's menu. Refusing every order on the site because a
 * manifest has a typo is plainly worse than letting one through, so failing open is obviously
 * right for those. This one is different in kind — 86'd means *the kitchen physically cannot
 * cook it* — so failing open sells food that does not exist, and the cost lands on a customer
 * waiting for a dish nobody is making.
 *
 * It still fails open, for two reasons. The registry itself decides this, not the guard: a class
 * that throws is skipped by `CheckoutGuardRegistry` before any code here runs, so a guard cannot
 * choose to be fatal without core changing. And the failure is recoverable in a way an
 * unreachable shop is not — the counter sees the ticket, telephones, and refunds one order,
 * whereas a guard that could close the shop closes it for everybody at once with a message
 * nobody can act on.
 *
 * So: the honest limit is that this is enforced in depth and not guaranteed. `ModifierPricing`
 * refuses at the cart, this refuses at the till, and both are skipped if the theme's tables are
 * missing. A shop that needs a hard boundary needs `checkout.strict` in the manifest — a core
 * change, and a real decision, not this default quietly reinterpreted.
 *
 * No branch named (every single-outlet shop), a missing table after a half-run migration, or any
 * error, and the order proceeds. A dish nobody 86'd refuses nothing, which is the overwhelming
 * majority of every order.
 */
class BranchSoldOutGuard implements CheckoutGuard
{
    /**
     * How many dishes are named before the sentence summarises.
     *
     * Matches `BranchMenuGuard`: a refusal a customer can act on names the food, and one listing
     * eleven dishes is a wall they read as a fault.
     */
    private const NAME_LIMIT = 3;

    public function check(array $cart, array $fields, array $context = []): ?string
    {
        $outletId = (int) ($fields['outlet_id'] ?? 0);

        if ($outletId <= 0) {
            return null;
        }

        // The branch must be one a customer could actually have chosen. A deactivated branch's
        // 86 list is not a reason to refuse an order somebody is placing right now.
        if (! Outlet::query()->active()->whereKey($outletId)->exists()) {
            return null;
        }

        $ids = collect($cart)
            ->map(fn ($line) => (int) ($line['id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return null;
        }

        $soldOut = DB::table('outlet_product_unavailable')
            ->where('outlet_id', $outletId)
            ->whereIn('product_id', $ids)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($soldOut === []) {
            return null;
        }

        return $this->refusal($soldOut);
    }

    /**
     * @param  array<int,int>  $soldOut
     */
    private function refusal(array $soldOut): string
    {
        $titles = Product::query()
            ->whereIn('id', $soldOut)
            ->pluck('title', 'id')
            ->map(function ($title) {
                $value = is_string($title) ? json_decode($title, true) : $title;
                $value = is_array($value)
                    ? ($value[app()->getLocale()] ?? reset($value))
                    : $value;

                return trim((string) $value);
            })
            ->filter()
            ->values()
            ->all();

        if ($titles === []) {
            // The dishes were deleted between the cart and the till. Refuse without naming
            // them rather than printing ids nobody recognises.
            return __('Something in your order has sold out for today. Please review your basket.');
        }

        if (count($titles) > self::NAME_LIMIT) {
            return __(':count items in your order have sold out for today. Please review your basket.', [
                'count' => count($titles),
            ]);
        }

        // Branched rather than a `trans_choice` pluralisation string: with at most three names
        // there are exactly two sentences, and two plain keys are easier for a translator to
        // get right than one pipe-delimited one.
        if (count($titles) === 1) {
            return __(':dish has sold out for today. Please remove it to continue.', [
                'dish' => $titles[0],
            ]);
        }

        return __(':dishes have sold out for today. Please remove them to continue.', [
            'dishes' => implode(', ', $titles),
        ]);
    }
}
