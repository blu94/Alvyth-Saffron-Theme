{{-- The collection rail. Included from index.blade.php on whichever side the block chose,
     so the markup exists once and the two positions cannot drift apart. --}}
<aside class="col-lg-3">
    <nav class="saffron-listing__rail" aria-label="{{ $categoriesLabel }}">
        <h2 class="saffron-listing__rail-title">{{ $categoriesLabel }}</h2>
        <ul class="saffron-listing__rail-list">
            <li>
                <a href="{{ url('/products') }}"
                   class="saffron-listing__rail-link @if($current === null) is-active @endif">{{ __('Everything') }}</a>
            </li>
            @foreach($collections as $collection)
                <li>
                    <a href="{{ $collection['url'] }}"
                       class="saffron-listing__rail-link @if($current === $collection['id']) is-active @endif">{{ $collection['title'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
</aside>
