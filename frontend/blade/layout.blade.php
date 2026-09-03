<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo_title ?? 'Ovynt Saffron Theme' }}</title>
    @php
        $seoData = $page?->seo?->data ?? [];
        $seoLoc  = $locale ?? app()->getLocale();

        $getSeoStr = function ($key) use ($seoData, $seoLoc) {
            $val = $seoData[$key] ?? null;
            if (is_array($val)) return $val[$seoLoc] ?? $val['en'] ?? current($val) ?? '';
            return $val ?? '';
        };

        $metaDesc     = $getSeoStr('description');
        $metaKeywords = $getSeoStr('keywords');
        $canonical    = $getSeoStr('canonical');
        $jsonLd       = $seoData['jsonLd'] ?? '';

        $robots = $seoData['robots'] ?? [];
        $robotsContent = [];
        $robotsContent[] = ($robots['index'] ?? true) ? 'index' : 'noindex';
        $robotsContent[] = ($robots['follow'] ?? true) ? 'follow' : 'nofollow';
        if ($robots['noarchive'] ?? false) $robotsContent[] = 'noarchive';
        if ($robots['noimageindex'] ?? false) $robotsContent[] = 'noimageindex';
        $robotsTag = implode(', ', $robotsContent);

        $og        = $seoData['og'] ?? [];
        $ogType    = $og['type'] ?? 'website';
        $ogTitle   = is_array($og['title'] ?? null) ? ($og['title'][$seoLoc] ?? $og['title']['en'] ?? '') : ($og['title'] ?? '');
        $ogDesc    = is_array($og['description'] ?? null) ? ($og['description'][$seoLoc] ?? $og['description']['en'] ?? '') : ($og['description'] ?? '');
        $ogImage   = !empty($og['assets'][0]['path']) ? $og['assets'][0]['path'] : '';

        $twitter      = $seoData['twitter'] ?? [];
        $twitterCard  = $twitter['card'] ?? 'summary_large_image';
        $twitterTitle = is_array($twitter['title'] ?? null) ? ($twitter['title'][$seoLoc] ?? $twitter['title']['en'] ?? '') : ($twitter['title'] ?? '');
        $twitterDesc  = is_array($twitter['description'] ?? null) ? ($twitter['description'][$seoLoc] ?? $twitter['description']['en'] ?? '') : ($twitter['description'] ?? '');
        $twitterImage = !empty($twitter['assets'][0]['path']) ? $twitter['assets'][0]['path'] : '';
    @endphp

    @if($metaDesc)<meta name="description" content="{{ $metaDesc }}">@endif
    @if($metaKeywords)<meta name="keywords" content="{{ $metaKeywords }}">@endif
    @if($canonical)<link rel="canonical" href="{{ $canonical }}">@endif
    <meta name="robots" content="{{ $robotsTag }}">

    @php
        // Alternates are a claim that the same content exists at each of these addresses, so
        // they are printed only for a page that actually resolved. A 404 was declaring language
        // alternates for a URL that exists in no language at all.
        //
        // Judged on the page's own type, not on a status code: `ThemeController` computes the
        // status but does not pass it to the view, and the hard-error path renders with no
        // `$page` at all — so both routes into an error template have to be recognised here.
        $errorTypes = ['404', '500', 'MAINTENANCE'];
        $emitAlternates = !empty($availableLocales)
            && isset($page)
            && ! in_array((string) ($page->type ?? ''), $errorTypes, true);

        if ($emitAlternates) {
            $segments = explode('/', trim(request()->path(), '/'));

            if (!empty($segments) && array_key_exists($segments[0], $availableLocales)) {
                array_shift($segments);
            }

            $newPath = implode('/', $segments);

            // ...and the query string rides along. Without it every page of a paginated listing
            // named page one as its alternate, which tells a crawler the wrong thing about nine
            // pages out of ten.
            $altQuery = request()->getQueryString();
            $altSuffix = $altQuery ? '?' . $altQuery : '';
        }
    @endphp

    @if($emitAlternates)
        @foreach($availableLocales as $code => $name)
            <link rel="alternate" hreflang="{{ $code }}" href="{{ ($code === $defaultLocale ? url($newPath) : url($code . '/' . $newPath)) . $altSuffix }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ url($newPath) . $altSuffix }}">
    @endif

    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $ogTitle ?: ($seo_title ?? 'Ovynt') }}">
    <meta property="og:url" content="{{ request()->url() }}">
    @if($ogDesc)<meta property="og:description" content="{{ $ogDesc }}">@endif
    @if($ogImage)<meta property="og:image" content="{{ asset($ogImage) }}">@endif

    <meta name="twitter:card" content="{{ $twitterCard }}">
    <meta name="twitter:title" content="{{ $twitterTitle ?: ($seo_title ?? 'Ovynt') }}">
    @if($twitterDesc)<meta name="twitter:description" content="{{ $twitterDesc }}">@endif
    @if($twitterImage)<meta name="twitter:image" content="{{ asset($twitterImage) }}">@endif

    @if($jsonLd)
    <script type="application/ld+json">
    {!! $jsonLd !!}
    </script>
    @endif

    {{-- Restaurant / opening hours on every page, plus the dish as a Product on a dish page.
         Built from the settings and menu already maintained; the SEO tab's own JSON-LD above
         is the operator's and stays untouched. --}}
    <x-theme.component name="StructuredData" :data="['settings' => $settings ?? [], 'page' => $page ?? null]" />

    @if(!empty($appSettings['favicon']))
        <link rel="icon" href="{{ Str::startsWith($appSettings['favicon'], ['/', 'http']) ? $appSettings['favicon'] : '/storage/' . $appSettings['favicon'] }}">
    @endif

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    @php
        // The deployed directory is slugged from the manifest `title`, not from `slug` —
        // "Ovynt Saffron Theme" installs to storage/app/themes/ovynt-saffron-theme/.
        $themeSlug = $themeConfig['slug'] ?? 'ovynt-saffron-theme';
        // Bumped by "Clear System Cache" so a manual clear re-busts every asset URL on
        // top of the per-file mtime.
        $assetRevSuffix = ($assetRev ?? '') !== '' ? '-' . $assetRev : '';
    @endphp

    @if(file_exists(public_path($path = "themes/{$themeSlug}/frontend/assets/css/theme.css")))
        <link rel="stylesheet" href="{{ asset($path) }}?v={{ filemtime(public_path($path)) }}{{ $assetRevSuffix }}">
    @endif

    @if(file_exists(public_path($path = "themes/{$themeSlug}/frontend/assets/js/storefront.min.css")))
        <link rel="stylesheet" href="{{ asset($path) }}?v={{ filemtime(public_path($path)) }}{{ $assetRevSuffix }}">
    @endif

    @if(!empty($google_fonts_url))
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="{{ $google_fonts_url }}" rel="stylesheet">
    @endif

    @include('partials.layout.dynamic-styles')

    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>

    @php
        $apiJsPath = "themes/{$themeSlug}/frontend/assets/js/api.js";
    @endphp
    <script src="{{ asset($apiJsPath) }}?v={{ file_exists(public_path($apiJsPath)) ? filemtime(public_path($apiJsPath)) : '' }}{{ $assetRevSuffix }}"></script>

    @if(file_exists(public_path($path = "themes/{$themeSlug}/frontend/assets/js/storefront.min.js")))
        <script src="{{ asset($path) }}?v={{ filemtime(public_path($path)) }}{{ $assetRevSuffix }}"></script>
    @endif

    @if(($settings['animations_enabled'] ?? true) && file_exists(public_path($path = "themes/{$themeSlug}/frontend/assets/js/motion.js")))
        @php
            // Theme-wide reveal settings, handed to motion.js. The html class is what
            // arms the hidden starting state in _motion.scss, and it is only added when
            // the visitor has not asked for reduced motion — so nothing is ever hidden
            // for someone whose device will not animate it back in.
            $motionConfig = [
                'effect'   => (string) ($settings['animation_effect'] ?? 'fade-up'),
                'duration' => (int) ($settings['animation_duration'] ?? 700),
                'stagger'  => (int) ($settings['animation_stagger'] ?? 100),
                'offset'   => (int) ($settings['animation_offset'] ?? 10),
                'once'     => filter_var($settings['animation_once'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        @endphp
        <script>
            window.SaffronMotion = @json($motionConfig);
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                document.documentElement.classList.add('saffron-motion');
            }
        </script>
        <script defer src="{{ asset($path) }}?v={{ filemtime(public_path($path)) }}{{ $assetRevSuffix }}"></script>
    @endif
</head>
<body class="saffron-body">

    <div id="saffron-app-root">
        {{-- How you want it and from where, above everything it governs. In the layout rather
             than as a page-builder section because it must not be optional: a gate an operator
             can forget to place on the menu page is a gate that is not there. It renders
             nothing at all for a shop with one mode and no branches, which is every install
             that has not used the Outlets module. --}}
        <x-theme.component name="OrderGate" />

        @include('partials.layout.header')

        <main id="MainContent" class="content-for-layout focus-none" role="main" tabindex="-1">
            @yield('content')
        </main>

        @include('partials.layout.footer')
    </div>

    @if($settings['go_to_top_enabled'] ?? true)
    <div id="saffron-go-to-top">
        <button type="button" class="saffron-go-to-top" :class="{ 'is-visible': visible }"
                @click="scrollToTop" aria-label="{{ __('Go to top') }}">
            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2"
                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="18 15 12 9 6 15"></polyline>
            </svg>
        </button>
    </div>
    <script>
    (function () {
        const { createApp, ref, onMounted, onUnmounted } = Vue;
        createApp({
            setup() {
                const visible = ref(false);
                const onScroll = () => { visible.value = window.scrollY > 300; };
                const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

                onMounted(() => { window.addEventListener('scroll', onScroll, { passive: true }); onScroll(); });
                onUnmounted(() => window.removeEventListener('scroll', onScroll));

                return { visible, scrollToTop };
            },
        }).mount('#saffron-go-to-top');
    })();
    </script>
    @endif

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
