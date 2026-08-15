@extends('layout')

@section('content')
<div class="saffron-homepage">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        {{-- The homepage is authored entirely through the page builder. Surface the gap to
             the developer, never to a hungry customer. --}}
        @if(config('app.debug'))
            <div class="saffron-container saffron-section">
                <div class="saffron-menu__empty">
                    <p class="mb-0">{{ __('The home page has no builder rows yet. Add a Dish Grid or Menu Sections block to the HOME page in the admin.') }}</p>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
