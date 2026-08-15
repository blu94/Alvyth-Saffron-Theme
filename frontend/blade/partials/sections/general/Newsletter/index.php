<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Support\Motion;

/**
 * The email-capture strip — birthdays-and-offers signups, which is where a restaurant
 * collects most of its list (audit B5).
 *
 * Modelled on Ella's Newsletter section, with one deliberate difference in how it sends.
 * Ella posts `{email}` to a free-text endpoint the operator types in; no such endpoint
 * exists in core, so on a fresh install that section quietly succeeds without storing
 * anything. Here the section is a heading around the **DynamicForm** component, and the
 * operator picks a form from the Forms module — the same client, endpoint and lead storage
 * the footer's form block uses, so a signup lands beside the shop's other leads and the
 * success message is the form's own. A form with an Email field and a Submit button is the
 * whole setup.
 *
 * Presentation only here: heading, subheading, small print, layout and tone. The form's
 * fields, validation and success copy belong to the form.
 */
class Newsletter
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        // The section's Status control — Disabled renders nothing (audit A9).
        if (($data['status'] ?? 'active') === 'disabled') {
            return '';
        }

        // The autocomplete may store the bare slug or an object carrying it — same two
        // shapes footer-block.blade.php reads for its form block.
        $rawSlug  = $data['form_slug'] ?? '';
        $formSlug = is_array($rawSlug)
            ? (string) ($rawSlug['value'] ?? $rawSlug['slug'] ?? '')
            : (string) $rawSlug;

        $layout = ($data['layout'] ?? 'centered') === 'split' ? 'split' : 'centered';
        $tone   = in_array($data['tone'] ?? 'sand', ['sand', 'dark', 'accent'], true) ? $data['tone'] : 'sand';

        return View::make($themeViewPath, [
            'heading'     => $this->translate($data['heading'] ?? '', $locale),
            'subheading'  => $this->translate($data['subheading'] ?? '', $locale),
            'note'        => $this->translate($data['note'] ?? '', $locale),
            'formSlug'    => trim($formSlug),
            'layout'      => $layout,
            'tone'        => $tone,
            'motionAttrs' => Motion::sectionAttributes($data),
            'locale'      => $locale,
            'data'        => $data,
        ])->render();
    }

    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
