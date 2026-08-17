<section class="saffron-menu {{ $ratioClass }}" {!! $motionAttrs !!}>
    <div class="saffron-container">
        @if($heading !== '' || $subheading !== '')
            <header class="saffron-menu__head" data-saffron-reveal>
                @if($heading !== '')
                    <h2 class="saffron-section-title">{{ $heading }}</h2>
                @endif
                @if($subheading !== '')
                    <p class="saffron-section-lede saffron-menu__lede mb-0">{{ $subheading }}</p>
                @endif
            </header>
        @endif

        @if($sections->isEmpty())
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('The menu is being updated. Please check back shortly.') }}</p>
                @if(config('app.debug'))
                    <p class="small mb-0 mt-2">
                        {{ __('No menu sections resolved. A menu section is an active Category with at least one active dish attached to it.') }}
                    </p>
                @endif
            </div>
        @else
            @if(config('app.debug') && !$usedTypeFilter)
                <p class="small saffron-muted">
                    {{ __('No category is typed "Product", so every active category holding dishes is being shown. Set the type on your menu categories to control this exactly.') }}
                </p>
            @endif

            @if($showSectionNav)
                <nav class="saffron-menu__nav {{ $stickyNav ? 'saffron-menu__nav--sticky' : '' }} scrollbar-hide"
                     aria-label="{{ __('Menu sections') }}">
                    @foreach($sections as $section)
                        <a href="#{{ $section['anchor'] }}" class="saffron-menu__nav-link">{{ $section['title'] }}</a>
                    @endforeach
                </nav>
            @endif

            @foreach($sections as $section)
                <div id="{{ $section['anchor'] }}" class="saffron-menu__section">
                    @if($showSectionTitles)
                        <header class="saffron-menu__section-head" data-saffron-reveal>
                            <h3 class="saffron-menu__section-title">{{ $section['title'] }}</h3>
                            @if($showSectionCount)
                                <span class="saffron-menu__section-count">
                                    {{ trans_choice('{1} :count dish|[2,*] :count dishes', $section['total'], ['count' => $section['total']]) }}
                                </span>
                            @endif
                        </header>
                    @endif

                    @if($section['desc'] !== '')
                        <p class="saffron-menu__section-desc">{{ $section['desc'] }}</p>
                    @endif

                    <div class="row g-3 g-md-4" data-saffron-reveal-group>
                        @foreach($section['dishes'] as $dish)
                            <div class="{{ $layout === 'list' ? 'col-12' : $gridColClass }}" data-saffron-reveal>
                                <x-theme.component name="DishCard" :data="['dish' => $dish, 'settings' => $cardSettings]" />
                            </div>
                        @endforeach
                    </div>

                    @if($section['hasMore'])
                        <div class="saffron-menu__section-more">
                            <a href="{{ $section['url'] }}" class="saffron-btn saffron-btn--outline saffron-btn--sm">
                                {{ __('See all in') }} {{ $section['title'] }}
                            </a>
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</section>
