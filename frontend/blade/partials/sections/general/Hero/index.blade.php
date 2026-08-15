@if($slides->isNotEmpty())
<section class="saffron-hero {{ $heightClass }}" id="{{ $uid }}">
    @foreach($slides as $i => $slide)
        <div class="saffron-hero__slide saffron-hero__slide--{{ $slide['align'] }} @if($i === 0) is-active @endif" data-hero-slide>
            <picture>
                @if($slide['image_mobile'] !== '')
                    <source media="(max-width: 575px)" srcset="{{ $slide['image_mobile'] }}">
                @endif
                @if($slide['image_tablet'] !== '')
                    <source media="(max-width: 991px)" srcset="{{ $slide['image_tablet'] }}">
                @endif
                {{-- The first slide is the page's likely LCP element, so it loads eagerly;
                     the rest are hidden behind the rotator and can wait. --}}
                <img class="saffron-hero__image" src="{{ $slide['image'] }}"
                     alt="{{ $slide['title'] !== '' ? $slide['title'] : __('Restaurant banner') }}"
                     @if($i === 0) fetchpriority="high" @else loading="lazy" @endif>
            </picture>

            @if($slide['title'] !== '' || $slide['subtitle'] !== '' || $slide['buttons']->isNotEmpty())
                <div class="saffron-hero__content">
                    @if($slide['title'] !== '')
                        <h2 class="saffron-hero__title">{{ $slide['title'] }}</h2>
                    @endif
                    @if($slide['subtitle'] !== '')
                        <p class="saffron-hero__subtitle">{{ $slide['subtitle'] }}</p>
                    @endif
                    @if($slide['buttons']->isNotEmpty())
                        <div class="saffron-hero__actions">
                            @foreach($slide['buttons'] as $button)
                                <a href="{{ $button['url'] }}" target="{{ $button['target'] }}"
                                   class="saffron-btn saffron-btn--accent">{{ $button['text'] }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endforeach

    @if($slides->count() > 1)
        <div class="saffron-hero__dots" role="tablist" aria-label="{{ __('Slides') }}">
            @foreach($slides as $i => $slide)
                <button type="button" class="saffron-hero__dot @if($i === 0) is-active @endif"
                        data-hero-dot="{{ $i }}" aria-label="{{ __('Go to slide :number', ['number' => $i + 1]) }}"></button>
            @endforeach
        </div>
    @endif
</section>

@if($slides->count() > 1)
<script>
(function () {
    var root = document.getElementById(@json($uid));
    if (!root) return;

    var slides = root.querySelectorAll('[data-hero-slide]');
    var dots = root.querySelectorAll('[data-hero-dot]');
    var current = 0;
    var timer = null;

    function show(next) {
        slides[current].classList.remove('is-active');
        dots[current].classList.remove('is-active');
        current = (next + slides.length) % slides.length;
        slides[current].classList.add('is-active');
        dots[current].classList.add('is-active');
    }

    // Rotation restarts (rather than merely continues) after a manual pick, so the
    // slide someone chose gets a full interval on screen instead of whatever was
    // left of the previous slide's.
    function start() {
        if (timer) clearInterval(timer);
        timer = setInterval(function () { show(current + 1); }, 6000);
    }

    dots.forEach(function (dot, i) {
        dot.addEventListener('click', function () { show(i); start(); });
    });

    start();
})();
</script>
@endif
@endif
