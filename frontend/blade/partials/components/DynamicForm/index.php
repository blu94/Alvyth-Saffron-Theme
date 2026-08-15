<?php

namespace Theme\Components;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Any form the Forms module built, rendered inline wherever a template asks for it.
 *
 * Until this existed a restaurant had no way to take a booking or catering enquiry on the
 * storefront: the Forms module and its lead storage were in core, `ThemeApi.forms` was in the
 * theme's JS, and nothing drew the form. Ported from Ella's component of the same name — the
 * schema fetch, the field switch and the submit are Ella's, the markup is Saffron's, and every
 * user-visible string now goes through `__()` instead of living in the script.
 *
 * `$data['slug']` selects the form. Fields arrive from `GET /api/storefront/forms/{slug}` and
 * the answers go to `POST /api/storefront/forms/{slug}/submit`, so a form placed here files
 * its leads exactly where the same form on a page would.
 */
class DynamicForm
{
    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $slug = trim((string) ($data['slug'] ?? ''));

        if ($slug === '') {
            return '';
        }

        return View::make($themeViewPath, [
            'slug'   => $slug,
            'uid'    => 'saffron-form-' . Str::slug($slug) . '-' . Str::random(6),
            'intro'  => (string) ($data['intro'] ?? ''),
            'locale' => $locale,
            'labels' => [
                'loading'    => __('Loading…'),
                'loadFailed' => __('This form could not be loaded just now.'),
                'submit'     => __('Send'),
                'submitting' => __('Sending…'),
                'success'    => __('Thank you — we have received your message.'),
                'failed'     => __('That could not be sent. Please try again.'),
                'choose'     => __('Choose…'),
            ],
        ])->render();
    }
}
