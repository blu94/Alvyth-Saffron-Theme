<aside class="saffron-dish-rail">
    @if($showCourses)
        <section class="saffron-dish-rail__panel">
            <h2 class="saffron-dish-rail__title">{{ __('Menu') }}</h2>

            @if(count($courses) > 0)
                <ul class="saffron-dish-rail__list">
                    @foreach($courses as $course)
                        <li>
                            <a href="{{ $course['url'] }}" class="saffron-dish-rail__course">
                                <span>{{ $course['title'] }}</span>
                                <span class="saffron-dish-rail__count">{{ $course['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="saffron-dish-rail__empty">{{ __('No courses yet.') }}</p>
            @endif
        </section>
    @endif

    @if($showDishes)
        <section class="saffron-dish-rail__panel">
            <h2 class="saffron-dish-rail__title">{{ __('More from the menu') }}</h2>

            @if(count($dishes) > 0)
                <ul class="saffron-dish-rail__list saffron-dish-rail__list--dishes">
                    @foreach($dishes as $dish)
                        <li>
                            <a href="{{ $dish['url'] }}" class="saffron-dish-rail__dish">
                                <span class="saffron-dish-rail__thumb">
                                    @if($dish['image'])
                                        <img src="{{ $dish['image'] }}" alt="" loading="lazy">
                                    @else
                                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="1.3"
                                             fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 12h18"></path>
                                            <path d="M5 12a7 7 0 0 1 14 0"></path>
                                            <path d="M4 16h16"></path>
                                            <path d="M7 20h10"></path>
                                        </svg>
                                    @endif
                                </span>
                                <span class="saffron-dish-rail__dish-body">
                                    <span class="saffron-dish-rail__dish-title">{{ $dish['title'] }}</span>
                                    <span class="saffron-dish-rail__dish-price">{{ $dish['price'] }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="saffron-dish-rail__empty">{{ __('Nothing else on the menu yet.') }}</p>
            @endif
        </section>
    @endif
</aside>
