<section class="saffron-section saffron-listing">
    <div class="saffron-container">
        <div class="row g-4 g-lg-5">

            @if($hasSidebar && $sidebarPos === 'left')
                @include('partials.sections.commerce.ProductGrid.filters')
            @endif

            <div class="{{ $hasSidebar ? 'col-lg-9' : 'col-12' }}">

                <div class="saffron-listing__bar">
                    <p class="saffron-listing__count mb-0">
                        @if($showCount && $dishes->total() > 0)
                            {{ __('Showing :from–:to of :total', [
                                'from'  => $dishes->firstItem(),
                                'to'    => $dishes->lastItem(),
                                'total' => $dishes->total(),
                            ]) }}
                        @endif
                    </p>

                    @include('partials.sections.commerce.sort')
                </div>

                @if($dishes->isEmpty())
                    <div class="saffron-menu__empty">
                        <p class="mb-0">{{ __('Nothing matches those choices. Try widening them.') }}</p>
                    </div>
                @else
                    <div class="row g-3 g-md-4" data-saffron-reveal-group>
                        @foreach($dishes as $dish)
                            <div class="{{ $gridColClass }}" data-saffron-reveal>
                                <x-theme.component name="DishCard" :data="['dish' => $dish, 'settings' => $cardSettings]" />
                            </div>
                        @endforeach
                    </div>

                    @if($dishes->hasPages())
                        <div class="saffron-listing__pager">
                            @include('components.builder.sections.partials.pager', ['paginator' => $dishes])
                        </div>
                    @endif
                @endif
            </div>

            @if($hasSidebar && $sidebarPos === 'right')
                @include('partials.sections.commerce.ProductGrid.filters')
            @endif
        </div>
    </div>
</section>
