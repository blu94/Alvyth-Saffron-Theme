<?php

namespace Theme\Components;

use App\Models\Product;
use Illuminate\Support\Facades\View;
use Theme\Backend\Support\ThemeSettings;

/**
 * Customer reviews on a dish — the list, the average, and the form to add one.
 *
 * **Ella has had this the whole time and Saffron had none of it** (register E3). It was filed
 * as core work in an early revision, which was wrong: `InteractionController` has stored and
 * returned a `rating` all along, so the display half never needed a core change. This is Ella's
 * `ProductDetails` reviews block ported to Saffron's markup — same endpoints, same payload
 * keys, same guest rules.
 *
 * **The rules are core's and are not restated here.** A comment is held `pending` and is
 * invisible to the storefront unless the author is a signed-in customer whose order contains
 * this dish, an author who has been approved before, or somebody holding `comments.update`.
 * The **Verified Buyer** badge comes from `OrderRepository::hasPurchased()` and can never be
 * earned by typing an email — anyone who has seen a receipt knows a customer's address. Guests
 * must give a name and an email; the email is stored, never published, and is the only handle a
 * moderator has on an author with no account. Submissions are rate-limited 6/minute and
 * 30/hour per IP.
 *
 * So this component only has to be honest about one thing: **a held review will not appear in
 * the list that reloads after submitting**, and the form says so. Without that the author looks
 * for their own review, cannot find it, and writes it again.
 *
 * The list is fetched in the browser rather than rendered server-side, as Ella does it: a dish
 * page is cached and read far more often than it is reviewed, and paging through reviews should
 * not reload the ordering form above them.
 */
class DishReviews
{
    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $dish = $data['dish'] ?? View::shared('page');

        if (! $dish instanceof Product) {
            return '';
        }

        // One switch for the feature, the way Saved Dishes has one — three switches for three
        // surfaces is three places to leave it half on.
        if (! ThemeSettings::bool('reviews_enabled', true)) {
            return '';
        }

        return View::make($themeViewPath, [
            'dishId' => $dish->id,
            'uid'    => 'dish-reviews-' . $dish->id,
            // Every string the script needs, as ONE variable.
            //
            // `@json()` splits its argument on top-level commas to find Blade's optional
            // `$options` and `$depth` parameters, so `@json(__('Rate :n of 5', ['n' => ':n']))`
            // compiles to `json_encode(__('Rate :n of 5'), ['n' => ':n'])` — a PHP parse error
            // that took the whole dish page to a 500. Building the array here and passing the
            // one variable is the documented way round it, and it also keeps the strings beside
            // every other translated label this theme ships.
            'labels' => [
                'write'    => __('Write a review'),
                'cancel'   => __('Cancel'),
                'submit'   => __('Submit review'),
                'sending'  => __('Sending…'),
                'review'   => __('review'),
                'reviews'  => __('reviews'),
                'rate'     => __('Rate :n out of 5', ['n' => ':n']),
                'noRating' => __('Please choose a star rating.'),
                'noBody'   => __('Please write your review.'),
                'noName'   => __('Please enter your name.'),
                'noEmail'  => __('Please enter your email address.'),
                'held'     => __('Thank you. Your review will appear once it has been read.'),
                'live'     => __('Thank you. Your review is now on the page.'),
                'failed'   => __('Something went wrong. Please try again.'),
                'offline'  => __('Could not reach the shop. Please try again.'),
            ],
        ])->render();
    }
}
