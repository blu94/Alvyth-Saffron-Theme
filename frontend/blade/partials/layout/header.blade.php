@php
    // The hard-404 and hard-maintenance paths render the layout with only $themeConfig, so
    // $locale and $settings are absent there. Resolving the locale through `??` keeps this
    // partial from raising an undefined-variable warning — which HandleExceptions promotes
    // to an ErrorException, turning a missing 404 page into a 500.
    //
    // $settings needs the same default: every `$settings['key'] ?? …` read below is
    // null-safe on its own, but the BARE variable handed to SearchDrawer is not — it was
    // the one read that still turned the degraded path into a 500.
    $loc      = $locale ?? app()->getLocale();
    $settings = $settings ?? [];

    $t = function ($value) use ($loc) {
        if (is_array($value)) return $value[$loc] ?? $value['en'] ?? (count($value) ? current($value) : '');
        return $value ?? '';
    };

    $linkUrl = function ($value) {
        if (is_array($value)) return $value['url'] ?? '';
        return (string) ($value ?? '');
    };

    $siteTitle = $appSettings['site_title'] ?? 'Ovynt';

    $announcementOn   = $settings['announcement_enabled'] ?? true;
    $announcementText = $t($settings['announcement_text'] ?? '');

    $logo = $settings['header_logo'] ?? null;
    if (is_array($logo)) {
        $logoPath = $logo[0]['path'] ?? ($logo['path'] ?? '');
    } else {
        $logoPath = (string) ($logo ?? '');
    }
    $logoUrl = $logoPath ? (Str::startsWith($logoPath, ['/', 'http']) ? $logoPath : '/storage/' . ltrim($logoPath, '/')) : '';

    // Two levels: a top link and its dropdown. The repeater is `recursive` in the schema
    // (the same core BuilderRepeater mechanism Ella's mega menu uses), so each item may
    // carry `children` — resolved here once so the markup never touches the raw bag.
    $resolveLink = function (array $l) use ($t, $linkUrl) {
        return ['label' => $t($l['label'] ?? ''), 'url' => $linkUrl($l['url'] ?? '')];
    };

    $headerLinks = collect($settings['header_links'] ?? [])
        ->map(function ($l) use ($resolveLink) {
            $item = $resolveLink($l);
            $item['children'] = collect($l['children'] ?? [])
                ->map($resolveLink)
                ->filter(fn ($c) => $c['label'] !== '')
                ->values()
                ->all();
            return $item;
        })
        ->filter(fn ($l) => $l['label'] !== '')
        ->values();

    $showSearch = $settings['header_show_search'] ?? true;
    $showCart   = $settings['header_show_cart'] ?? true;

    // Centered by default — the restaurant look. 'left' docks the links beside the logo.
    $navCentered = ($settings['header_nav_position'] ?? 'center') === 'center';

    $ctaLabel = $t($settings['header_cta_label'] ?? '');
    $ctaUrl   = $linkUrl($settings['header_cta_url'] ?? '') ?: '/';

    $currentPath = '/' . trim(request()->path(), '/');
@endphp

@if($announcementOn && $announcementText !== '')
    <div class="saffron-announcement">{{ $announcementText }}</div>
@endif

<header id="saffron-header" class="saffron-header">
    <div class="saffron-container">
        <div class="saffron-header__inner">
            <a href="{{ url('/') }}" class="saffron-header__brand">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $siteTitle }}" class="saffron-header__logo">
                @else
                    {{ $siteTitle }}
                @endif
            </a>

            @if($headerLinks->isNotEmpty())
                <nav class="saffron-header__nav @if($navCentered) saffron-header__nav--center @endif" aria-label="{{ __('Primary') }}">
                    @foreach($headerLinks as $link)
                        @if(empty($link['children']))
                            <a href="{{ $link['url'] ?: '#' }}" class="saffron-header__link"
                               @if($link['url'] === $currentPath) aria-current="page" @endif>{{ $link['label'] }}</a>
                        @else
                            {{-- Opens on hover AND on :focus-within, so the keyboard reaches
                                 every child; the caret is a real button so a touch device —
                                 which has no hover — has something to tap that is not the
                                 parent link itself. --}}
                            <div class="saffron-header__item saffron-header__item--has-dropdown">
                                <a href="{{ $link['url'] ?: '#' }}" class="saffron-header__link"
                                   @if($link['url'] === $currentPath) aria-current="page" @endif>{{ $link['label'] }}</a>
                                <button type="button" class="saffron-header__caret" aria-expanded="false"
                                        aria-label="{{ __('Show :section links', ['section' => $link['label']]) }}"
                                        data-dropdown-toggle>
                                    <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" stroke-width="2.4"
                                         fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M6 9l6 6 6-6"/>
                                    </svg>
                                </button>
                                <div class="saffron-header__dropdown">
                                    @foreach($link['children'] as $child)
                                        <a href="{{ $child['url'] ?: '#' }}" class="saffron-header__dropdown-link"
                                           @if($child['url'] === $currentPath) aria-current="page" @endif>{{ $child['label'] }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </nav>
            @endif

            <div class="saffron-header__actions">
                @if($showSearch)
                    <button type="button" class="saffron-header__icon-btn" data-search-open
                            aria-label="{{ __('Search the menu') }}" aria-haspopup="dialog">
                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="1.8"
                             fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="10" cy="10" r="7"></circle>
                            <line x1="21" y1="21" x2="15" y2="15"></line>
                        </svg>
                    </button>
                @endif

                <a href="{{ url('/profile') }}" class="saffron-header__icon-btn" aria-label="{{ __('Account') }}">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="1.8"
                         fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"></path>
                    </svg>
                </a>

                @if($showCart)
                    <a href="{{ url('/cart') }}" class="saffron-header__icon-btn" aria-label="{{ __('Cart') }}">
                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="1.8"
                             fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 2 4 6v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6l-2-4z"></path>
                            <line x1="4" y1="6" x2="20" y2="6"></line>
                            <path d="M16 10a4 4 0 0 1-8 0"></path>
                        </svg>
                        <span v-if="cartCount > 0" v-cloak class="saffron-header__cart-count">@{{ cartCount }}</span>
                    </a>
                @endif

                @if($ctaLabel !== '')
                    <a href="{{ $ctaUrl }}" class="saffron-btn saffron-btn--accent saffron-btn--sm saffron-header__cta">{{ $ctaLabel }}</a>
                @endif

                @if($headerLinks->isNotEmpty())
                    <button type="button" class="saffron-header__icon-btn saffron-header__toggle"
                            @click="mobileOpen = !mobileOpen"
                            :aria-expanded="mobileOpen ? 'true' : 'false'"
                            aria-controls="saffron-mobile-nav"
                            aria-label="{{ __('Menu') }}">
                        <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="1.8"
                             fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="3" y1="7" x2="21" y2="7"></line>
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="17" x2="21" y2="17"></line>
                        </svg>
                    </button>
                @endif
            </div>
        </div>

        @if($headerLinks->isNotEmpty())
            <nav id="saffron-mobile-nav" class="saffron-mobile-nav" v-if="mobileOpen" v-cloak
                 aria-label="{{ __('Primary mobile') }}">
                @foreach($headerLinks as $link)
                    <a href="{{ $link['url'] ?: '#' }}" class="saffron-mobile-nav__link">{{ $link['label'] }}</a>
                    {{-- Children listed inline and indented, not behind a second tap: the
                         drawer already IS the expanded state, and a restaurant's nav is
                         short enough that a nested accordion would only hide things. --}}
                    @foreach($link['children'] as $child)
                        <a href="{{ $child['url'] ?: '#' }}" class="saffron-mobile-nav__link saffron-mobile-nav__link--child">{{ $child['label'] }}</a>
                    @endforeach
                @endforeach
                @if($ctaLabel !== '')
                    <a href="{{ $ctaUrl }}" class="saffron-btn saffron-btn--accent saffron-btn--block mt-3">{{ $ctaLabel }}</a>
                @endif
            </nav>
        @endif
    </div>
</header>

<script>
(function () {
    const { createApp, ref, computed, onMounted, onUnmounted } = Vue;

    createApp({
        setup() {
            const mobileOpen = ref(false);

            // Bound to the global reactive store shipped in storefront.min.js. It is the
            // single source of cart truth on the client; never keep a second copy here.
            const cartCount = computed(() => {
                if (!window.OvyntStore) return 0;
                return window.OvyntStore.cartList.reduce((total, item) => total + (item.quantity || 0), 0);
            });

            // A second tab adding a dish must be reflected here, so mirror localStorage
            // writes back into the reactive arrays in place rather than reassigning them.
            const onStorage = (e) => {
                if (e.key !== 'ovynt_cart' || !window.OvyntStore) return;
                try {
                    const next = JSON.parse(e.newValue || '[]');
                    if (Array.isArray(next)) {
                        window.OvyntStore.cartList.splice(0, window.OvyntStore.cartList.length, ...next);
                    }
                } catch (err) {
                    console.error('Failed to sync cart across tabs', err);
                }
            };

            onMounted(() => window.addEventListener('storage', onStorage));
            onUnmounted(() => window.removeEventListener('storage', onStorage));

            return { mobileOpen, cartCount };
        },
    }).mount('#saffron-header');

    // Dropdown toggles for pointers that cannot hover. Hover and keyboard focus open the
    // panel in CSS alone; this only handles the caret tap, closes on outside tap / Escape,
    // and keeps aria-expanded honest for screen readers. Delegated at the header rather
    // than bound per caret so it costs one listener however many menus there are.
    const header = document.getElementById('saffron-header');
    if (!header) return;

    const closeAll = () => {
        header.querySelectorAll('.saffron-header__item.is-open').forEach((item) => {
            item.classList.remove('is-open');
            const caret = item.querySelector('[data-dropdown-toggle]');
            if (caret) caret.setAttribute('aria-expanded', 'false');
        });
    };

    header.addEventListener('click', (e) => {
        const caret = e.target.closest('[data-dropdown-toggle]');
        if (!caret) return;
        e.preventDefault();
        const item   = caret.closest('.saffron-header__item');
        const opening = !item.classList.contains('is-open');
        closeAll();
        if (opening) {
            item.classList.add('is-open');
            caret.setAttribute('aria-expanded', 'true');
        }
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.saffron-header__item--has-dropdown')) closeAll();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
})();
</script>

@if($showSearch)
    <x-theme.component name="SearchDrawer" :data="['settings' => $settings]" />
@endif
