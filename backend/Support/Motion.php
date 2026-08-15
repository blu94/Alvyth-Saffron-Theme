<?php

namespace Theme\Backend\Support;

/**
 * Scroll-reveal options for a builder section.
 *
 * Every Saffron section schema offers the same four fields under Styling → Animation:
 * `animation` (theme default, none, or a named effect), `animation_duration` and
 * `animation_stagger` (theme default or a value in ms) and `animation_delay` (ms). This
 * turns them into the data attributes `frontend/assets/js/motion.js` reads, so each driver
 * adds one line and each blade prints one variable — the mapping lives here rather than
 * being repeated in every blade.
 *
 * The section root carries the *scope*: which effect its revealed children use, how fast,
 * how far apart, and how long the whole section waits. Elements inside marked
 * `data-saffron-reveal` animate; a `data-saffron-reveal-group` staggers its revealed
 * children. Whatever a section leaves on "theme default" is an attribute the script does
 * not find, and it falls back to the theme's Animation tab (`window.SaffronMotion`).
 * Nothing here reads those globals, so a section renders the same attributes whether or
 * not the feature is on and the script alone decides what moves.
 */
class Motion
{
    /** Effects the schemas offer and _motion.scss implements. Anything else is ignored. */
    public const EFFECTS = [
        'fade-up',
        'fade-down',
        'fade-in',
        'fade-left',
        'fade-right',
        'zoom-in',
        'fade-blur',
    ];

    /**
     * Attributes for a section root, ready to print unescaped: values are whitelisted or
     * cast to int, never operator text.
     *
     * Returns '' when the section opted out (`animation = none`), so the blade prints
     * nothing and the script leaves every child alone. `inherit` (the default) yields an
     * empty effect value, which the script resolves to the theme's default effect.
     */
    public static function sectionAttributes(?array $data): string
    {
        $data   = $data ?? [];
        $choice = trim((string) ($data['animation'] ?? 'inherit'));

        if ($choice === 'none') {
            return '';
        }

        $effect = in_array($choice, self::EFFECTS, true) ? $choice : '';
        $delay  = max(0, min(5000, (int) ($data['animation_delay'] ?? 0)));

        $attributes = sprintf('data-saffron-motion="%s" data-saffron-motion-delay="%d"', $effect, $delay);

        // "inherit" (or anything non-numeric) leaves the attribute out and the theme
        // default applies; a chosen value travels as milliseconds.
        if (is_numeric($data['animation_duration'] ?? null)) {
            $attributes .= sprintf(' data-saffron-motion-duration="%d"', max(100, min(4000, (int) $data['animation_duration'])));
        }

        if (is_numeric($data['animation_stagger'] ?? null)) {
            $attributes .= sprintf(' data-saffron-motion-stagger="%d"', max(0, min(1000, (int) $data['animation_stagger'])));
        }

        return $attributes;
    }
}
