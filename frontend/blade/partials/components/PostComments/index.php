<?php

namespace Theme\Components;

use App\Models\Post;
use App\Services\Storefront\InteractionSettings;
use Illuminate\Support\Facades\View;
use Theme\Backend\Support\ThemeSettings;

/**
 * Comments and likes on a blog post (register E7).
 *
 * **The same core endpoints the dish reviews use**, on a different type. Core resolves
 * `/api/storefront/interactions/{type}/{id}` by singularising the segment and looking for
 * `App\Models\{Type}`, so `posts` reaches `Post` with no core change — verified against
 * `InteractionController::getModelClass()` rather than assumed, because the whole reason E7 was
 * filed as theme work is that the endpoint already existed.
 *
 * **Deliberately not a copy of `DishReviews`.** A dish review carries a star rating and an
 * average that decides whether somebody orders; a comment on a story does not. Sending a
 * `rating` here would put a number on the post that no surface reads and that would drag its
 * `avg_rating` into the same aggregate a dish uses. So this posts comments with no rating, and
 * shows a like count instead of stars.
 *
 * **The moderation rules are core's and are not restated here.** A comment is stored `pending`
 * and stays invisible to the storefront unless its author is signed in and previously approved,
 * or holds `comments.update`. That matters more on a post than on a dish: there is no purchase
 * to auto-approve against, so on a blog **every guest comment is held**. The form says so in as
 * many words — without it an author looks for their comment, cannot find it, and writes it again.
 *
 * Guests must give a name and an email (core's `StoreInteractionCommentRequest` requires both);
 * the email is stored and never published. Submissions are rate-limited 6/minute, 30/hour per IP.
 */
class PostComments
{
    public function __construct(protected InteractionSettings $interactions)
    {
    }

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $post = $data['post'] ?? View::shared('page');

        // `blog.json` can route a Page of type BLOG to the same template, and a Page has no
        // interactions. Guard on the model rather than on the template.
        if (! $post instanceof Post) {
            return '';
        }

        // Two switches, and the store's is the outer one. Settings → Application →
        // Interactions & Sharing turns comments off for the whole storefront; this
        // theme's switch decides only whether stories carry them when they are on.
        // Reading the theme setting alone left an operator who had switched comments
        // off looking at a live comment form.
        if (! $this->interactions->commentsEnabled()) {
            return '';
        }

        if (! ThemeSettings::bool('post_comments_enabled', true)) {
            return '';
        }

        return View::make($themeViewPath, [
            'postId'    => $post->id,
            'uid'       => 'post-comments-' . $post->id,
            'showLikes' => $this->interactions->likesEnabled()
                && ThemeSettings::bool('post_likes_enabled', true),
            // One variable, one JSON object. `@json()` splits its argument on top-level commas
            // looking for Blade's optional `$options` and `$depth`, so a `__()` call carrying a
            // replacements array compiles to `json_encode($string, $array)` — a parse error that
            // took a whole page to a 500 once already.
            'labels' => [
                'write'    => __('Leave a comment'),
                'cancel'   => __('Cancel'),
                'submit'   => __('Post comment'),
                'sending'  => __('Sending…'),
                // A whole choice string, picked client-side by `ThemeApi.transChoice` — the
                // same shape the dish sheet renders server-side with `trans_choice`, so a
                // locale with more than two plural forms is not flattened to an either/or.
                'countChoice' => __('{1} :count comment|[2,*] :count comments'),
                'like'     => __('Like this story'),
                'unlike'   => __('Remove your like'),
                'noBody'   => __('Please write your comment.'),
                'noName'   => __('Please enter your name.'),
                'noEmail'  => __('Please enter your email address.'),
                'held'     => __('Thank you. Your comment will appear once it has been read.'),
                'live'     => __('Thank you. Your comment is now on the page.'),
                'failed'   => __('Something went wrong. Please try again.'),
                'offline'  => __('Could not reach the shop. Please try again.'),
                'empty'    => __('No comments yet. Be the first to leave one.'),
                'loading'  => __('Loading comments…'),
            ],
        ])->render();
    }
}
