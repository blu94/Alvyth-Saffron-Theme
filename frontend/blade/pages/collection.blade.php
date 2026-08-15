@extends('layout')

@section('content')
{{-- One menu section as its own page, reached at /collections/{slug}.

     The builder rows belong to the Category record, so "Burgers" can be laid out
     differently from "Drinks" without a template per course. There is deliberately no
     query fallback here: a page template has no driver hook, and fetching dishes from
     Blade would put data logic in the presentation layer. Drop a Menu Sections block on
     the category instead. --}}
<div class="saffron-collection">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @php
            $loc = $locale ?? app()->getLocale();
            $catTitle = is_array($page->title ?? '')
                ? ($page->title[$loc] ?? current($page->title))
                : ($page->title ?? '');
        @endphp
        <div class="saffron-container saffron-section">
            @if($catTitle)
                <h1 class="saffron-section-title mb-3">{{ $catTitle }}</h1>
            @endif
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('This part of the menu is being updated. Please check back shortly.') }}</p>
                @if(config('app.debug'))
                    <p class="small mb-0 mt-2">
                        {{ __('This category has no builder rows. Add a Menu Sections block to it, limited to this category slug.') }}
                    </p>
                @endif
            </div>
            <div class="mt-4">
                <a href="{{ url('/') }}" class="saffron-btn saffron-btn--dark">{{ __('Back to the menu') }}</a>
            </div>
        </div>
    @endif
</div>
@endsection
