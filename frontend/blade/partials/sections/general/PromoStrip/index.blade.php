@if($items->isNotEmpty())
<section class="saffron-promo">
    <div class="saffron-container">
        @if($heading !== '')
            <h2 class="saffron-section-title text-center mb-4">{{ $heading }}</h2>
        @endif

        <div class="row g-3 g-md-4">
            @foreach($items as $item)
                <div class="{{ $colClass }}">
                    @if($item['link'] !== '')
                        <a href="{{ $item['link'] }}" class="saffron-promo__card saffron-promo__card--link">
                            @include('partials.sections.general.PromoStrip.card', ['item' => $item])
                        </a>
                    @else
                        <div class="saffron-promo__card">
                            @include('partials.sections.general.PromoStrip.card', ['item' => $item])
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif
