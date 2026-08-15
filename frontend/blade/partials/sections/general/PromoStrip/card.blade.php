@if($item['eyebrow'] !== '')
    <span class="saffron-eyebrow">{{ $item['eyebrow'] }}</span>
@endif

@if($item['title'] !== '')
    <h3 class="saffron-promo__title">{{ $item['title'] }}</h3>
@endif

@if($item['text'] !== '')
    <p class="saffron-promo__text mb-0">{{ $item['text'] }}</p>
@endif

@if($item['code'] !== '')
    <span class="saffron-promo__code">{{ $item['code'] }}</span>
@endif
