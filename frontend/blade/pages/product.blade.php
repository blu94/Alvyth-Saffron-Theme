@extends('layout')

@section('content')
{{-- The dish page. Reached at /products/{slug} and the deep link every dish card points at.

     Phase 1 renders it from the builder rows on the Product record, which lets the core
     Product Details section carry it. The dish sheet — variants, modifier groups, special
     instructions, live running total — is phase 2 and will land as its own theme section
     rather than as logic in this template. --}}
<div class="saffron-dish-page">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @php
            $loc = $locale ?? app()->getLocale();
            $dishTitle = is_array($page->title ?? '')
                ? ($page->title[$loc] ?? current($page->title))
                : ($page->title ?? '');
        @endphp
        <div class="saffron-container saffron-section">
            @if($dishTitle)
                <h1 class="saffron-section-title mb-3">{{ $dishTitle }}</h1>
            @endif
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('This dish is being updated. Please check back shortly.') }}</p>
                @if(config('app.debug'))
                    <p class="small mb-0 mt-2">
                        {{ __('This product has no builder rows. Add a Product Details section to it.') }}
                    </p>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
