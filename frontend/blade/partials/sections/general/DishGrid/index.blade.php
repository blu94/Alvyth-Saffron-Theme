<section class="saffron-dish-grid {{ $ratioClass }}" {!! $motionAttrs !!}>
    <div class="saffron-container">
        @if($heading !== '' || $subheading !== '')
            <header class="saffron-dish-grid__head {{ $align === 'center' ? 'saffron-dish-grid__head--center' : '' }}" data-saffron-reveal>
                @if($heading !== '')
                    <h2 class="saffron-section-title">{{ $heading }}</h2>
                @endif
                @if($subheading !== '')
                    <p class="saffron-section-lede mb-0">{{ $subheading }}</p>
                @endif
            </header>
        @endif

        @if($dishes->isEmpty())
            @if(config('app.debug'))
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
            @endif
        @else
            <div class="row g-3 g-md-4" data-saffron-reveal-group>
                @foreach($dishes as $dish)
                    <div class="{{ $layout === 'list' ? 'col-12' : $gridColClass }}" data-saffron-reveal>
                        <x-theme.component name="DishCard" :data="['dish' => $dish, 'settings' => $cardSettings]" />
                    </div>
                @endforeach
            </div>

            @if($ctaLabel !== '')
                <div class="saffron-dish-grid__foot" data-saffron-reveal>
                    <a href="{{ $ctaUrl }}" class="saffron-btn saffron-btn--dark">{{ $ctaLabel }}</a>
                </div>
            @endif
        @endif
    </div>
</section>
