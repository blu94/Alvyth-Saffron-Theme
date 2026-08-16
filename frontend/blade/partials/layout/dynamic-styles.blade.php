<style id="ovy-dynamic-appearance">
    :root {
        {{-- Generic pass. $settings is absent on the hard-404 / hard-maintenance render
             paths, which pass only $themeConfig — never iterate it unguarded. Colours are
             auto-prefixed --color-, everything else becomes --{kebab-key}. --}}
        @foreach(($settings ?? []) as $key => $value)
            @if(is_string($value) || is_numeric($value))
                @php
                    $isColor  = preg_match('/^#([a-fA-F0-9]{3,8})\b|^rgba?\(/', trim((string) $value));
                    $kebabKey = \Illuminate\Support\Str::kebab($key);
                    $cssVar   = ($isColor && !str_starts_with($kebabKey, 'color')) ? "--color-{$kebabKey}" : "--{$kebabKey}";
                @endphp
                {{ $cssVar }}: {{ $value }};
            @endif
        @endforeach

        {{-- Curated pass, and it must come second so it wins.
             `Str::kebab` leaves underscores intact, so a snake_case setting key yields
             `--heading_font_family` rather than `--heading-font-family`. The font keys are
             deliberately snake_case because ThemeFontService only recognises `font_family`
             and `*_font_family` when deciding which Google font to fetch. This block is
             what bridges the two conventions, so the SCSS can name its tokens normally. --}}
        @php
            $tokenMap = [
                'font_family'         => '--font-family',
                'heading_font_family' => '--heading-font-family',
                'cardRadius'          => '--card-radius',
                'dish_image_ratio'    => '--dish-image-ratio',
                'header_logo_height'  => '--header-logo-height',
                'announcementBg'      => '--announcement-bg',
                'announcementColor'   => '--announcement-color',
                'headerBg'            => '--header-bg',
                'surface'             => '--color-surface',
                'cardSurface'         => '--color-card-surface',
            ];
        @endphp
        @foreach($tokenMap as $settingKey => $cssVar)
            @if(($value = ($settings[$settingKey] ?? null)) !== null && (is_string($value) || is_numeric($value)) && trim((string) $value) !== '')
                {{ $cssVar }}: {{ $value }};
            @endif
        @endforeach

        {{-- The accent's hover shade. `--color-accent-dark` is compiled into theme.css and
             carries every hover, focus and "price" tint, but no setting names it — so an
             operator who changed the Accent Colour kept saffron on every hover (audit A18).
             Derived from the accent rather than asked for as a second colour, so the two
             cannot drift apart. --}}
        @if(is_string($settings['accent'] ?? null) && trim($settings['accent']) !== '')
            --color-accent-dark: color-mix(in srgb, var(--color-accent), #000 15%);
        @endif
    }

    @stack('dynamic_styles')
</style>
