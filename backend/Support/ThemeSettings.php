<?php

namespace Theme\Backend\Support;

use App\Models\Theme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * The active theme's saved settings, for drivers and components.
 *
 * Blade templates receive `$settings` as a view variable from ThemeController, but a
 * section driver is called from `section.blade.php` with the section's own data only, and
 * nothing in core `View::share()`s the settings. Several drivers read
 * `View::shared('settings')` for their theme-level fallbacks — the closed-shop message,
 * the footer address, the sold-out wording — and got `null` every time, so those fallbacks
 * were dead code that happened to look right because the defaults matched.
 *
 * This reads what ThemeController reads: the published `active-{database}.json`, through
 * the same forever cache key, so a driver sees exactly the settings the layout sees and
 * pays no file read on a warm request. Republishing (a settings save in admin, an
 * activation) forgets that key, which is what makes a change visible.
 */
class ThemeSettings
{
    /** @var array<string, mixed>|null */
    protected static ?array $settings = null;

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (static::$settings !== null) {
            return static::$settings;
        }

        try {
            $path = Theme::activeConfigReadPath();

            $config = Cache::rememberForever('active_theme_config', function () use ($path) {
                return File::exists($path) ? json_decode(File::get($path), true) : null;
            });

            static::$settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];
        } catch (\Throwable $e) {
            report($e);
            static::$settings = [];
        }

        return static::$settings;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all()[$key] ?? $default;
    }

    /**
     * A switch-type setting, `$default` when unset.
     */
    public static function bool(string $key, bool $default = true): bool
    {
        $value = static::all()[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
