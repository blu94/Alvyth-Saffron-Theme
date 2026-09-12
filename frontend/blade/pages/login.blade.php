@extends('layout')

@section('content')
{{-- Carried by the core Login Form section. Alvyth encrypts the password in the browser
     before it leaves, so the form must be the core one rather than a hand-rolled POST. --}}
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @if(config('app.debug'))
            <div class="saffron-section">
                <div class="saffron-menu__empty">
                    <p class="mb-0">{{ __('The LOGIN page has no builder rows. Add a Login Form section to it.') }}</p>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
