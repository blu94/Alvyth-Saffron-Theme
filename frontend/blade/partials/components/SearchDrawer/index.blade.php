<div class="saffron-search" id="saffron-search" hidden>
    <div class="saffron-search__backdrop" data-search-close></div>

    <div class="saffron-search__panel" role="dialog" aria-modal="true" aria-label="{{ $placeholder }}">
        <div class="saffron-search__head">
            <form class="saffron-search__form" @submit.prevent="onSubmit">
                <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="1.8"
                     fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="10" cy="10" r="7"></circle>
                    <line x1="21" y1="21" x2="15" y2="15"></line>
                </svg>
                <input type="search" ref="inputRef" v-model="query" @input="onInput"
                       class="saffron-search__input" placeholder="{{ $placeholder }}"
                       aria-label="{{ $placeholder }}" autocomplete="off">
                <span class="saffron-search__spinner" v-if="loading" aria-hidden="true"></span>
            </form>

            <button type="button" class="saffron-search__close" data-search-close
                    aria-label="{{ __('Close search') }}">&times;</button>
        </div>

        <div class="saffron-search__body">
            <div v-if="query.length >= 2">
                <p class="saffron-search__label" v-if="results.length">
                    {{ $labels['results'] }} <span class="saffron-search__count">@{{ results.length }}</span>
                </p>

                <div class="saffron-search__empty" v-else-if="!loading">
                    <p class="mb-0">{{ $labels['noResults'] }} <strong>@{{ query }}</strong></p>
                    @if($browseAllUrl)
                        <a href="{{ $browseAllUrl }}" class="saffron-btn saffron-btn--outline saffron-search__browse">{{ $labels['browseAll'] }}</a>
                    @endif
                </div>

                <p class="saffron-search__empty" v-else>{{ $labels['searching'] }}</p>

                <a v-for="item in results" :key="item.id" :href="item.url" class="saffron-search__result">
                    <span class="saffron-search__thumb">
                        <img v-if="item.image" :src="item.image" :alt="item.title">
                    </span>
                    <span class="saffron-search__meta">
                        <span class="saffron-search__cat" v-if="item.category">@{{ item.category }}</span>
                        <span class="saffron-search__name">@{{ item.title }}</span>
                        <span class="saffron-search__price">
                            <span :class="{ 'saffron-search__price--now': item.compare_price }">@{{ item.price }}</span>
                            <s v-if="item.compare_price">@{{ item.compare_price }}</s>
                        </span>
                    </span>
                </a>
            </div>

            @if(!empty($sections) || $browseAllUrl)
                <div v-if="query.length < 2">
                    @if(!empty($sections))
                        <p class="saffron-search__label">{{ __('Browse the menu') }}</p>
                        <div class="saffron-search__chips">
                            @foreach($sections as $section)
                                <a href="{{ $section['url'] }}" class="saffron-search__chip">{{ $section['title'] }}</a>
                            @endforeach
                        </div>
                    @endif
                    @if($browseAllUrl)
                        <a href="{{ $browseAllUrl }}" class="saffron-search__browse saffron-search__browse--link">{{ $labels['browseAll'] }}</a>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    const { createApp, ref, nextTick } = Vue;

    const root = document.getElementById('saffron-search');
    if (!root) return;

    createApp({
        setup() {
            const query    = ref('');
            const results  = ref([]);

            // The ids this branch serves, or null for "everything". Null and an empty array are
            // opposite answers and must not be collapsed: null shows the whole menu, empty shows
            // nothing, and reading them as one would blank the search of every shop that has
            // never restricted a branch.
            const branchMenu = @json($branchMenu);
            const loading  = ref(false);
            const inputRef = ref(null);
            let debounce   = null;

            const search = (q) => {
                if (q.length < 2) {
                    results.value = [];
                    loading.value = false;
                    return;
                }
                loading.value = true;
                window.ThemeApi.search.query(q)
                    // Narrowed to the branch being browsed (register O18a, phase 3). Core's
                    // search endpoint serves every theme and knows nothing about outlets, so the
                    // filter belongs here — and `branchMenu` is null unless this branch actually
                    // restricts, so an ordinary shop's results are untouched.
                    .then(data => {
                        const all = Array.isArray(data) ? data : [];

                        // The gate publishes the current branch's menu when a customer switches
                        // without navigating; `branchMenu` is what this page was rendered with.
                        // Live answer first, baked answer second, "everything" last.
                        // Exclusions, not a menu. `undefined` means the gate has not published
                        // anything on this page, so fall back to what the server rendered with;
                        // `null` from the server means "no branch chosen", and both are treated
                        // as "exclude nothing" below rather than as "show nothing".
                        const hidden = window.__saffronBranchHidden !== undefined
                            ? window.__saffronBranchHidden
                            : branchMenu;

                        results.value = ! Array.isArray(hidden) || hidden.length === 0
                            ? all
                            : all.filter(item => ! hidden.includes(Number(item.id)));
                    })
                    .catch(() => { results.value = []; })
                    .finally(() => { loading.value = false; });
            };

            const onInput = () => {
                clearTimeout(debounce);
                if (query.value.length < 2) {
                    results.value = [];
                    loading.value = false;
                    return;
                }
                loading.value = true;
                debounce = setTimeout(() => search(query.value), 300);
            };

            // Enter goes to the shop's own products page carrying the term, which that page's
            // grid now reads. The URL comes from the driver rather than being written here: the
            // slug is translatable, and a hard-coded `/products` also dropped the locale prefix,
            // so a diner browsing `/ms/...` landed on the default locale's menu.
            //
            // With no products page there is nowhere honest to send them, so Enter keeps them in
            // the drawer with the live results already on screen rather than navigating to a 404.
            const searchUrl = @json($searchUrl);

            const onSubmit = () => {
                const q = query.value.trim();
                if (q.length > 0 && searchUrl) {
                    window.location.href = searchUrl + '?q=' + encodeURIComponent(q);
                }
            };

            const focusInput = () => nextTick(() => inputRef.value && inputRef.value.focus());

            root.addEventListener('saffron:search-open', focusInput);

            return { query, results, loading, inputRef, onInput, onSubmit };
        },
    }).mount('#saffron-search');

    const open = () => {
        root.hidden = false;
        document.body.classList.add('saffron-search-open');
        root.dispatchEvent(new CustomEvent('saffron:search-open'));
    };
    const close = () => {
        root.hidden = true;
        document.body.classList.remove('saffron-search-open');
    };

    document.querySelectorAll('[data-search-open]').forEach(el => {
        el.addEventListener('click', (e) => { e.preventDefault(); open(); });
    });
    root.querySelectorAll('[data-search-close]').forEach(el => el.addEventListener('click', close));

    // Escape closes it. A panel that covers the menu with no keyboard way out is a trap for
    // anyone not using a mouse.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !root.hidden) close();
    });
})();
</script>
