{{-- The sort control, shared by both listing sections so they cannot drift apart.

     A `<details>`, and the choice is deliberate after the filter rail's `<details>` had to be
     torn out: that one failed because it needed CSS to *force it open* on desktop, which a
     stylesheet cannot do to `::details-content`. This one is closed at every width by design,
     so there is nothing to force and nothing to fight. It also keeps the whole control
     keyboard- and screen-reader-native without a line of JavaScript.

     Every option stays a real GET link carrying the rest of the query string, so a sorted
     listing is still a shareable URL and the back button still behaves. `page` is cleared:
     page 4 of one order is not page 4 of another. --}}
@if($showSort)
    <details class="saffron-sort">
        <summary class="saffron-sort__toggle">
            <span class="saffron-sort__label">{{ __('Sort by') }}</span>
            <span class="saffron-sort__current">{{ $currentSortLabel }}</span>
            <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" stroke-width="2.4"
                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 9l6 6 6-6"/>
            </svg>
        </summary>
        <div class="saffron-sort__panel">
            @foreach($sortOptions as $value => $label)
                <a href="{{ request()->fullUrlWithQuery(['sort' => $value ?: null, 'page' => null]) }}"
                   class="saffron-sort__link @if($currentSort === (string) $value) is-active @endif"
                   @if($currentSort === (string) $value) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </div>
    </details>
@endif
