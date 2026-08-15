@extends('layout')

@section('content')
{{-- The customer's account: order history, reorder, saved addresses. Carried by the core
     Profile section in phase 1.

     The `account` plugin slot is mandatory — it is where a plugin renders standing,
     balances or membership, and a theme that omits it drops that region silently. --}}
<div class="saffron-profile">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-container saffron-section">
            <h1 class="saffron-section-title mb-3">{{ __('Your account') }}</h1>
            @if(config('app.debug'))
                <div class="saffron-menu__empty">
                    <p class="mb-0">{{ __('The PROFILE page has no builder rows. Add a Profile section to it.') }}</p>
                </div>
            @endif
        </div>
    @endif

    <div class="saffron-container">
        <x-plugin-slot name="account" />
    </div>
</div>
@endsection
