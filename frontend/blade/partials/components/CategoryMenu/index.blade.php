<div class="saffron-container saffron-collection__head">
    @if($title !== '')
        <h1 class="saffron-section-title mb-2">{{ $title }}</h1>
    @endif
    @if($lede !== '')
        <p class="saffron-section-lede mb-0">{{ $lede }}</p>
    @endif
</div>

@if($menu !== '')
    {!! $menu !!}
@else
    <div class="saffron-container saffron-section pt-0">
        <div class="saffron-menu__empty">
            <p class="mb-0">{{ __('This part of the menu is being updated. Please check back shortly.') }}</p>
        </div>
        <div class="mt-4">
            <a href="{{ $homeUrl }}" class="saffron-btn saffron-btn--dark">{{ __('Back to the menu') }}</a>
        </div>
    </div>
@endif
