{{-- The filter rail. Every control is a GET link carrying the rest of the query string, so a
     narrowed listing is shareable, the back button behaves, and none of this needs JavaScript.
     `page` is always cleared: page 4 of an unfiltered list is rarely page 4 of a filtered one. --}}
<aside class="col-lg-3">
    {{-- A plain rail, deliberately not a `<details>`.

         It was one for about ten minutes: below lg the three groups stack to roughly a phone
         screen and a half, so collapsing them behind one control looked right. It shipped
         broken on the desktop this is mostly viewed on — a closed `<details>` has its content
         hidden by the browser through `::details-content { content-visibility: hidden }`, which
         a `display: block` on the child cannot override, so the rail's whole column rendered
         empty at every width. The stylesheet solves the same problem without fighting the user
         agent: below lg each group becomes a single scrollable row of chips, about 200px in
         total instead of 600, and the dishes stay on the first screen. --}}
    <div class="saffron-listing__rail">

        @if($showCats && !empty($categories))
            <nav class="saffron-listing__filter" aria-label="{{ $categoriesLabel }}">
                <h2 class="saffron-listing__rail-title">{{ $categoriesLabel }}</h2>
                <ul class="saffron-listing__rail-list">
                    <li>
                        <a href="{{ request()->fullUrlWithQuery(['category' => null, 'page' => null]) }}"
                           class="saffron-listing__rail-link @if(!request('category')) is-active @endif">{{ __('Everything') }}</a>
                    </li>
                    @foreach($categories as $category)
                        <li>
                            <a href="{{ request()->fullUrlWithQuery(['category' => $category['id'], 'page' => null]) }}"
                               class="saffron-listing__rail-link @if((int) request('category') === $category['id']) is-active @endif">{{ $category['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @if($showAvail)
            <nav class="saffron-listing__filter" aria-label="{{ __('Availability') }}">
                <h2 class="saffron-listing__rail-title">{{ __('Availability') }}</h2>
                <ul class="saffron-listing__rail-list">
                    <li>
                        <a href="{{ request()->fullUrlWithQuery(['availability' => null, 'page' => null]) }}"
                           class="saffron-listing__rail-link @if(request('availability') !== 'in_stock') is-active @endif">{{ __('Everything') }}</a>
                    </li>
                    <li>
                        <a href="{{ request()->fullUrlWithQuery(['availability' => 'in_stock', 'page' => null]) }}"
                           class="saffron-listing__rail-link @if(request('availability') === 'in_stock') is-active @endif">{{ __('Available today') }}</a>
                    </li>
                </ul>
            </nav>
        @endif

        @if($showType && count($types) > 1)
            {{-- Only worth drawing when there is a choice: a shop whose every dish is one type
                 gets a filter with a single option, which narrows nothing. --}}
            <nav class="saffron-listing__filter" aria-label="{{ __('Type') }}">
                <h2 class="saffron-listing__rail-title">{{ __('Type') }}</h2>
                <ul class="saffron-listing__rail-list">
                    <li>
                        <a href="{{ request()->fullUrlWithQuery(['type' => null, 'page' => null]) }}"
                           class="saffron-listing__rail-link @if(!request('type')) is-active @endif">{{ __('Everything') }}</a>
                    </li>
                    @foreach($types as $type)
                        <li>
                            <a href="{{ request()->fullUrlWithQuery(['type' => $type, 'page' => null]) }}"
                               class="saffron-listing__rail-link @if(request('type') === $type) is-active @endif">{{ ucfirst($type) }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
</aside>
