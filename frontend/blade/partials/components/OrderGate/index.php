<?php

namespace Theme\Components;

use Illuminate\Support\Facades\View;
use Theme\Backend\Handlers\ShippingMethodOutlet;
use Theme\Backend\Models\Outlet;
use Theme\Backend\Support\ThemeSettings;

/**
 * Which branch you are ordering from — asked before the menu (register O18a, D-3).
 *
 * **One question, and only one, because only one of them decides the menu.** A branch is a
 * kitchen: what it can cook is a property of it, so a customer who has not named a branch cannot
 * be shown an honest menu. Delivery-or-pickup decides nothing a customer sees before the cart —
 * it decides the fee, the minimum and which hours apply, all of which are cart concerns.
 *
 * **This block asked both for one afternoon, and the second question was wrong.** Asking how
 * *and* where before anybody has seen the food is two gates in front of a menu, and the second
 * buys nothing the cart cannot ask better: the cart's own mode picker already exists, already
 * re-renders the fee and the address panel, and is where a customer expects that choice. So the
 * mode went back to the cart and the branch stayed here.
 *
 * **D-3's real complaint was the cost arriving late, not the question.** A customer filling a
 * basket and only then meeting "delivery orders start at RM 40" is the defect; being asked
 * *delivery or pickup* at the cart is not. So the bar states the minimums the shop has actually
 * configured, next to the branch, on every page — the cost arrives before the basket without a
 * second question in front of the menu. A shop that has set no minimum states none.
 *
 * **Nothing is rendered for a shop with one branch or none**, which is every install that has not
 * used the Outlets module: one branch is not a question, it is a fact, and the menu it implies is
 * the whole menu.
 *
 * **It does not enforce.** The menu it scopes and the basket it reconciles are conveniences —
 * `localStorage` outlives the choice, a shared link arrives with somebody else's basket, and
 * `POST /api/storefront/checkout` is public. `BranchMenuGuard` re-checks every line server-side.
 * This is what stops the customer meeting that refusal at the payment button.
 */
class OrderGate
{
    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $settings = ThemeSettings::all();

        $branches = $this->branches($locale);

        // One branch is an answer, not a question — and a shop with none has never used the
        // Outlets module and must be untouched by this entirely.
        if (count($branches) < 2) {
            return '';
        }

        $payload = [
            'branches' => $branches,
            // Which outlet each pickup method stands for, so choosing a branch here selects the
            // matching method in core's cart list rather than asking the same question twice.
            'methodOutlets' => ShippingMethodOutlet::map(),
            // What it costs to order at all, stated with the branch rather than sprung at the
            // cart. Null where the operator set no minimum, which is the default.
            'minimums' => $this->minimums($settings),
            'labels'   => [
                'heading'   => __('Where are you ordering from?'),
                'sub'       => __('Your branch decides what we can cook for you, so we ask before showing the menu.'),
                'branch'    => __('Which branch?'),
                'confirm'   => __('Show me the menu'),
                'change'    => __('Change'),
                'close'     => __('Close'),
                'minDelivery' => __('Delivery from :amount'),
                'minPickup'   => __('Collection from :amount'),
                'dropTitle' => __('Some dishes are not served there'),
                'dropBody'  => __(':dishes cannot come with you to :branch. Remove them and carry on, or keep your basket and choose a different branch.'),
                'dropGo'    => __('Remove them and switch'),
                'dropStay'  => __('Keep my basket'),
                'and'       => __('and'),
            ],
        ];

        return View::make($themeViewPath, [
            'payload' => $payload,
            'uid'     => 'saffron-order-gate',
        ])->render();
    }

    /**
     * The minimum order for each mode, as the shop writes money.
     *
     * **Null, not zero, when the operator set none** — the two mean opposite things, and a bar
     * reading "Delivery from RM 0.00" would be inventing a rule nobody made. Read from the same
     * settings `MinimumOrderGuard` refuses on, so the sentence a customer reads before the menu
     * and the sentence that stops their checkout are the same number.
     *
     * @return array{delivery: ?string, pickup: ?string}
     */
    protected function minimums(array $settings): array
    {
        return [
            'delivery' => $this->money($settings['minimum_delivery'] ?? null),
            'pickup'   => $this->money($settings['minimum_pickup'] ?? null),
        ];
    }

    /** A configured amount in the shop's own currency, or null when there is none. */
    protected function money(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        try {
            $app = app(\App\Repositories\Setting\Application\ApplicationInterface::class)->getSettings();

            $symbol    = $app['currency_symbol'] ?? '$';
            $position  = $app['currency_position'] ?? 'prefix';
            $formatted = number_format((float) $value, 2);

            return $position === 'suffix' ? $formatted . $symbol : $symbol . $formatted;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The branches a customer may order from, and the menu each of them restricts to.
     *
     * **Every active outlet that does anything**, collecting or delivering: a branch that only
     * delivers is still a kitchen somebody orders from, and its menu is still its own.
     *
     * `menu` is **null for a branch that restricts nothing**, which is every branch until an
     * operator opts in — and null is deliberately not an empty array, because the two mean
     * opposite things. Null is "serves everything"; an empty array is "an operator ticked the
     * switch and has attached nothing yet", which genuinely serves nothing. Collapsing them would
     * either blank every existing shop's menu or silently ignore the switch.
     *
     * @return array<int,array{id:int,title:string,address:string,hidden:array<int,int>}>
     */
    protected function branches(string $locale): array
    {
        try {
            // Every dish anybody has made exclusive to anybody — where "anybody" is a branch that
            // is open. Fetched once for the whole page: each branch's hidden list is this set
            // minus that branch's own, so asking per branch would run the same query as many
            // times as the shop has doors.
            //
            // **Constrained to active, undeleted outlets for the same reason
            // {@see \Theme\Backend\Support\BranchScope::hidden()} is** — and it must be the same
            // rule, because this list is what the browser hides cards with while `BranchScope` is
            // what the server drops rows with. If the two disagreed, a dish would vanish from a
            // grid the server had already filled, or survive in one the server had emptied.
            // Without the join, a dish exclusive only to a closed branch is hidden at every open
            // one, so deactivating a branch quietly takes its specials off the whole shop.
            $restricted = \Illuminate\Support\Facades\DB::table('outlet_product as op')
                ->join('outlets as o', 'o.id', '=', 'op.outlet_id')
                ->whereNull('o.deleted_at')
                ->where('o.status', 'active')
                ->distinct()
                ->pluck('op.product_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return Outlet::query()
                ->active()
                ->where(fn ($q) => $q->where('offers_pickup', true)->orWhere('offers_delivery', true))
                ->orderByDesc('is_default')
                ->ordered()
                ->with(['exclusiveProducts' => fn ($q) => $q->select('products.id')])
                ->get()
                ->map(fn (Outlet $outlet) => [
                    'id'      => $outlet->id,
                    'title'   => $this->translate($outlet->title, $locale) ?: $outlet->slug,
                    'address' => trim((string) $outlet->address),
                    // The dishes to HIDE at this branch: everything anybody made exclusive, minus
                    // what this branch is named on. Computed per branch here rather than shipping
                    // the raw pivot and re-deriving the set difference in JavaScript — the rule
                    // lives in one place, and the browser gets a list it cannot misread.
                    //
                    // An empty array hides nothing, which is the answer for every branch of every
                    // shop that has never made a dish exclusive.
                    'hidden' => array_values(array_diff(
                        $restricted,
                        $outlet->exclusiveProducts->pluck('id')->map(fn ($id) => (int) $id)->all()
                    )),
                ])
                ->all();
        } catch (\Throwable $e) {
            // The same failing-open rule the rest of this theme's drivers follow: a storefront
            // rendering against a half-deployed import shows no gate rather than no page.
            report($e);

            return [];
        }
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
