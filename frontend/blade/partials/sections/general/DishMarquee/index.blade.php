@if($dishes->isNotEmpty())
@push('dynamic_styles')
#{{ $uid }} .saffron-marquee__track {
    animation-duration: {{ $speed }}s;
    animation-direction: {{ $reverse ? 'reverse' : 'normal' }};
}
@endpush
<section id="{{ $uid }}" class="saffron-marquee {{ $sizeClass }} {{ $pauseClass }}" {!! $motionAttrs !!}>
    @if($heading !== '' || $subheading !== '')
        <div class="saffron-container">
            <header class="saffron-marquee__head" data-saffron-reveal>
                @if($heading !== '')
                    <h2 class="saffron-section-title">{{ $heading }}</h2>
                @endif
                @if($subheading !== '')
                    <p class="saffron-section-lede mb-0">{{ $subheading }}</p>
                @endif
            </header>
        </div>
    @endif

    <div class="saffron-marquee__viewport" data-saffron-marquee data-saffron-reveal>
        <div class="saffron-marquee__track">
            @foreach([false, true] as $isClone)
                <ul class="saffron-marquee__list {{ $isClone ? 'saffron-marquee__list--clone' : '' }}" @if($isClone) aria-hidden="true" @endif>
                    @foreach($dishes as $dish)
                        <li class="saffron-marquee__item">
                            <a href="{{ $dish['url'] }}" class="saffron-marquee__card @if($dish['soldOut']) is-sold-out @endif" @if($isClone) tabindex="-1" @endif>
                                <span class="saffron-marquee__media">
                                    @if($dish['soldOut'])
                                        <span class="saffron-marquee__badge">{{ $soldOutLabel }}</span>
                                    @endif
                                    @if($dish['image'] !== '')
                                        <img src="{{ $dish['image'] }}" alt="{{ $dish['title'] }}" class="saffron-marquee__img" loading="lazy" decoding="async">
                                    @else
                                        <span class="saffron-marquee__placeholder">
                                            <svg viewBox="0 0 24 24" width="36" height="36" stroke="currentColor" stroke-width="1.4"
                                                 fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M3 12h18"></path>
                                                <path d="M5 12a7 7 0 0 1 14 0"></path>
                                                <path d="M4 16h16"></path>
                                                <path d="M7 20h10"></path>
                                            </svg>
                                        </span>
                                    @endif
                                </span>
                                <span class="saffron-marquee__body">
                                    <span class="saffron-marquee__title">{{ $dish['title'] }}</span>
                                    @if($dish['description'] !== '')
                                        <span class="saffron-marquee__desc saffron-clamp-2">{{ $dish['description'] }}</span>
                                    @endif
                                    @if($dish['price'])
                                        <span class="saffron-marquee__price">
                                            @if($dish['price']['from'])<small>{{ __('from') }}</small>@endif{{ $dish['price']['amount'] }}
                                        </span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    </div>

    @if($ctaLabel !== '')
        <div class="saffron-container">
            <div class="saffron-marquee__foot" data-saffron-reveal>
                <a href="{{ $ctaUrl }}" class="saffron-btn saffron-btn--dark">{{ $ctaLabel }}</a>
            </div>
        </div>
    @endif
</section>
@elseif(config('app.debug'))
<section class="saffron-marquee">
    <div class="saffron-container">
        <div class="saffron-dish-grid__empty">
            <p class="mb-0">
                @if($source !== 'latest' && $sourceSlug === '')
                    {{ __('No dishes shown: this section picks by :source but no slug was given.', ['source' => $source]) }}
                @elseif($source !== 'latest')
                    {{ __('No active dishes matched the :source slug ":slug".', ['source' => $source, 'slug' => $sourceSlug]) }}
                @else
                    {{ __('No active dishes exist yet.') }}
                @endif
            </p>
        </div>
    </div>
</section>
@endif
