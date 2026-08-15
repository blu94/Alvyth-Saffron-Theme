@extends('layout')

@section('content')
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @if(config('app.debug'))
            <div class="saffron-section">
                <div class="saffron-menu__empty">
                    <p class="mb-0">{{ __('The REGISTER page has no builder rows. Add a Register Form section to it.') }}</p>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
