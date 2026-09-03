@extends('layout')

@section('content')
    {{-- Guarded like every sibling template. Unguarded, a page whose builder rows were
         removed rendered a silently empty <main> with nothing to diagnose it by. --}}
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @if(config('app.debug'))
            <div class="saffron-section">
                <div class="saffron-menu__empty">
                    <p class="mb-0">{{ __('The FORGOT PASSWORD page has no builder rows. Add a Forgot Password Form section to it.') }}</p>
                </div>
            </div>
        @endif
    @endif
@endsection
