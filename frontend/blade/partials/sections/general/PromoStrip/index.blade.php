@if($items->isNotEmpty())
<section class="saffron-promo" {!! $motionAttrs !!}>
    <div class="saffron-container">
        @if($heading !== '')
            <h2 class="saffron-section-title text-center mb-4" data-saffron-reveal>{{ $heading }}</h2>
        @endif

        <div class="row g-3 g-md-4" data-saffron-reveal-group>
            @foreach($items as $item)
                <div class="{{ $colClass }}" data-saffron-reveal>
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
