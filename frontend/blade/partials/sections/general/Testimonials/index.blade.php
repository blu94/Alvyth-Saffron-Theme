@if(!empty($items))
<section id="{{ $uid }}" class="saffron-testimonials" {!! $motionAttrs !!}>
    <div class="saffron-container">
        @if($heading !== '' || $subheading !== '')
            <header class="saffron-testimonials__head {{ $align === 'center' ? 'saffron-testimonials__head--center' : '' }}">
                @if($heading !== '')
                    <h2 class="saffron-section-title">{{ $heading }}</h2>
                @endif
                @if($subheading !== '')
                    <p class="saffron-section-lede mb-0">{{ $subheading }}</p>
                @endif
            </header>
        @endif

        <div class="row g-4">
            @foreach($items as $item)
                <div class="{{ $colClass }}">
                    <figure class="saffron-testimonial h-100">
                        @if($item['rating'] > 0)
                            <div class="saffron-testimonial__stars" role="img"
                                 aria-label="{{ trans_choice('{1} :count out of 5|[2,*] :count out of 5', $item['rating'], ['count' => $item['rating']]) }}">
                                @for($i = 1; $i <= 5; $i++)
                                    <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="1.4"
                                         fill="{{ $i <= $item['rating'] ? 'currentColor' : 'none' }}"
                                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="m12 17.75-6.172 3.245 1.179-6.873-4.993-4.867 6.9-1.002L12 2l3.086 6.253 6.9 1.002-4.993 4.867 1.179 6.873z"></path>
                                    </svg>
                                @endfor
                            </div>
                        @endif

                        <blockquote class="saffron-testimonial__quote">{{ $item['quote'] }}</blockquote>

                        <figcaption class="saffron-testimonial__by">
                            @if($item['avatar'] !== '')
                                <span class="saffron-testimonial__avatar">
                                    <img src="{{ $item['avatar'] }}" alt="" loading="lazy" decoding="async">
                                </span>
                            @elseif($item['author'] !== '')
                                <span class="saffron-testimonial__avatar" aria-hidden="true">
                                    {{ Str::upper(Str::substr($item['author'], 0, 1)) }}
                                </span>
                            @endif

                            <span class="saffron-testimonial__who">
                                @if($item['author'] !== '')
                                    <span class="saffron-testimonial__author">{{ $item['author'] }}</span>
                                @endif
                                @if($item['role'] !== '')
                                    <span class="saffron-testimonial__role">{{ $item['role'] }}</span>
                                @endif
                            </span>
                        </figcaption>
                    </figure>
                </div>
            @endforeach
        </div>
    </div>
</section>
@elseif(config('app.debug'))
<section class="saffron-container saffron-section">
    <div class="saffron-menu__empty">
        <p class="mb-0">{{ __('Testimonials has no active quotes yet. Add one to this block in the page builder.') }}</p>
    </div>
</section>
@endif
