@if($items->isNotEmpty())
<section class="saffron-faq" {!! $motionAttrs !!}>
    <div class="saffron-container">
        @if($heading !== '' || $subheading !== '')
            <header class="saffron-faq__head" data-saffron-reveal>
                @if($heading !== '')
                    <h2 class="saffron-section-title">{{ $heading }}</h2>
                @endif
                @if($subheading !== '')
                    <p class="saffron-section-lede mb-0">{{ $subheading }}</p>
                @endif
            </header>
        @endif

        <div class="saffron-faq__list" data-saffron-reveal-group>
            @foreach($items as $item)
                <details class="saffron-faq__item" @if($item['open']) open @endif data-saffron-reveal>
                    <summary class="saffron-faq__question">
                        <span>{{ $item['question'] }}</span>
                        <svg class="saffron-faq__chevron" viewBox="0 0 24 24" width="18" height="18"
                             stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </summary>
                    @if($item['answer'] !== '')
                        <div class="saffron-faq__answer">{!! nl2br(e($item['answer'])) !!}</div>
                    @endif
                </details>
            @endforeach
        </div>
    </div>
    @if($jsonLd !== '')
        <script type="application/ld+json">{!! $jsonLd !!}</script>
    @endif
</section>
@endif
