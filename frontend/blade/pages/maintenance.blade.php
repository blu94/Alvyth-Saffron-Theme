@extends('layout')

@section('content')
{{-- Site-wide maintenance, which is the only on/off core has. It is not the same as being
     closed for the night — service windows are theme data and are enforced from phase 6. --}}
<div class="saffron-container">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="saffron-status">
            <div class="saffron-status__code">&#9788;</div>
            <h1 class="saffron-status__title">{{ __('The kitchen is closed for maintenance') }}</h1>
            <p class="saffron-status__text">
                {{ __('We are making a few changes and will be taking orders again shortly. Thanks for your patience.') }}
            </p>
        </div>
    @endif
</div>
@endsection
