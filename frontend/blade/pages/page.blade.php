@extends('layout')

@section('content')
{{-- The generic page template, and the one a "Menu" page resolves to.

     There is no MENU page type in core and a theme cannot add one, so the menu is a
     MENU_SECTIONS_SECTION block placed on an ordinary page through the page builder.
     That block is what this template renders. --}}
<div class="saffron-page">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @php
            $loc = $locale ?? app()->getLocale();
            $pageTitle = is_array($page->title ?? '')
                ? ($page->title[$loc] ?? current($page->title))
                : ($page->title ?? '');
            $pageDesc = is_array($page->description ?? '')
                ? ($page->description[$loc] ?? current($page->description))
                : ($page->description ?? '');
        @endphp
        <div class="saffron-container saffron-section">
            @if($pageTitle)
                <h1 class="saffron-section-title">{{ $pageTitle }}</h1>
            @endif
            @if($pageDesc)
                <p class="saffron-section-lede">{{ strip_tags($pageDesc) }}</p>
            @endif
        </div>
    @endif
</div>
@endsection
