<nav class="saffron-breadcrumbs" aria-label="{{ __('Breadcrumb') }}">
    <div class="saffron-container">
        <ol class="saffron-breadcrumbs__list">
            @foreach($trail as $crumb)
                @if($loop->last)
                    <li class="saffron-breadcrumbs__item" aria-current="page">{{ $crumb['label'] }}</li>
                @else
                    <li class="saffron-breadcrumbs__item">
                        <a href="{{ $crumb['url'] }}" class="saffron-breadcrumbs__link">{{ $crumb['label'] }}</a>
                    </li>
                @endif
            @endforeach
        </ol>
    </div>
    @if($jsonLd !== '')
        <script type="application/ld+json">{!! $jsonLd !!}</script>
    @endif
</nav>
