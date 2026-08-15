<?php

namespace Theme\Backend\Support;

/**
 * "Theme default, unless this section says otherwise."
 *
 * The Restaurant settings tab carries theme-wide presentation defaults — menu layout,
 * dishes per row, whether cards show a price, tags, a description — and the menu sections
 * carry the same controls. For a whole release the two never met: the section schemas
 * defaulted every control to a concrete value (`grid`, `true`), the builder stored that
 * value, and the driver read only the section's copy, so the theme-wide switch changed
 * nothing anywhere (audit A8). The fix is the shape the Animation controls and the dish
 * image ratio already use: the section offers a *Theme default* choice, and the driver
 * resolves section → theme → built-in fallback through here.
 *
 * Both readers accept every form the value has ever been stored in — a boolean from the
 * old switch, `show`/`hide` from the new select, `"1"`/`"0"`, and `""`/`inherit` for
 * "no opinion" — so a page saved before the schema changed keeps rendering as it did.
 */
class SectionSetting
{
    /**
     * A yes/no the section may override.
     *
     * A boolean or an explicit `show`/`hide`/`1`/`0`/`true`/`false` on the section wins;
     * anything else (`""`, `inherit`, null) defers to the theme value, and a theme value
     * that is itself unset yields `$fallback`.
     */
    public static function bool(mixed $section, mixed $theme, bool $fallback = true): bool
    {
        $own = self::asBool($section);

        if ($own !== null) {
            return $own;
        }

        return self::asBool($theme) ?? $fallback;
    }

    /**
     * A choice from a closed list the section may override.
     *
     * A non-empty section value wins (anything other than `""` and `inherit`); otherwise
     * the theme's; otherwise `$fallback`. Callers still validate the result against their
     * own list — this only decides *whose* value is used, not whether it is legal.
     */
    public static function choice(mixed $section, mixed $theme, string $fallback): string
    {
        foreach ([$section, $theme] as $candidate) {
            $value = is_scalar($candidate) ? trim((string) $candidate) : '';

            if ($value !== '' && strtolower($value) !== 'inherit') {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * Null when the value expresses no opinion; a bool when it does.
     */
    protected static function asBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || is_array($value)) {
            return null;
        }

        return match (strtolower(trim((string) $value))) {
            'show', '1', 'true', 'yes', 'on'  => true,
            'hide', '0', 'false', 'no', 'off' => false,
            default                           => null,
        };
    }
}
