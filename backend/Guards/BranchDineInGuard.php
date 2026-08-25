<?php

namespace Theme\Backend\Guards;

use App\Contracts\Storefront\CheckoutGuard;
use Theme\Backend\Models\Outlet;

/**
 * A branch with no dining room does not take a dine-in order.
 *
 * **Why this exists rather than the picker alone.** `outlets.offers_dine_in` decides whether a
 * branch seats anybody, and the cart narrows to it — but narrowing a control is presentation,
 * and the two halves of this question are answered by two different controls the theme does not
 * both own. The mode tiles narrow to the branch stored at the **gate**; the branch itself is then
 * chosen again from **core's** pickup-method list on the cart, which offers every collecting
 * branch there is. So a customer may legitimately answer the gate with a branch that dines in,
 * switch branches on the cart, and arrive at checkout as a diner at a counter with no tables.
 * `POST /api/storefront/checkout` is a public endpoint besides, and no picker guards it.
 *
 * Without this the order is taken, and what it costs is not abstract: the kitchen ticket reads
 * `DINE IN · Table 7 @ KLCC` for a branch whose dining room does not exist, and a waiter is sent
 * to find a party that is not there. The same shape as {@see BranchMenuGuard} — the hiding is
 * what the customer sees, the refusal is what holds.
 *
 * **What it refuses is narrow, on purpose.** Only an order that says it is dining in, at a branch
 * that says it does not seat diners. Everything else is somebody else's rule:
 *
 * - **No `dining` field** — a delivery or an ordinary collection. Never this guard's business.
 * - **No `outlet_id`** — a single-outlet shop, which has no branch to check and is the
 *   overwhelming majority of installs. Refusing it would take down every shop that never used
 *   outlets in order to protect the few that did.
 * - **A branch that is gone or inactive** — core's own shipping-method validation owns that, and
 *   a second refusal here would hand the customer two different reasons for one problem.
 * - **A dine-in order at a branch with no tables listed** — that is the shop-wide fallback count
 *   doing its job, and it is the upgrade path every install starts on.
 *
 * **Collection is part of the answer**, composed by {@see Outlet::dinesIn()} rather than repeated
 * here: a dine-in order is recorded as `fulfillment_type = pickup`, so a branch that has switched
 * collection off cannot seat a diner whatever its own dine-in column says.
 *
 * Fails open, like every guard in this theme — a manifest typo or a half-run migration must not
 * refuse every order on the site. That is affordable here because the worst a failure restores is
 * the behaviour this class was written to end, rather than something worse than it.
 */
class BranchDineInGuard implements CheckoutGuard
{
    public function check(array $cart, array $fields, array $context = []): ?string
    {
        // The one key that distinguishes eating in from taking away. Core stores `pickup` for
        // both — a diner needs no address and pays no delivery fee, and `fulfillment_type` has
        // only the two values — so `checkout_fields.dining` is the whole of the difference.
        if (($fields['dining'] ?? null) !== 'dine_in') {
            return null;
        }

        $outletId = (int) ($fields['outlet_id'] ?? 0);

        if ($outletId <= 0) {
            return null;
        }

        $outlet = Outlet::query()->active()->find($outletId);

        if (! $outlet || $outlet->dinesIn()) {
            return null;
        }

        return __('Dining in is not available at :branch. Please choose another branch, or collect your order instead.', [
            'branch' => $this->branchName($outlet),
        ]);
    }

    /**
     * The branch as the customer saw it named.
     *
     * `title` is translatable, so it arrives as a locale map or a plain string depending on how it
     * was written; the slug is the fallback, because a refusal naming an empty branch is a refusal
     * nobody can act on.
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
