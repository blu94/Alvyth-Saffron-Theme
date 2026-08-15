@extends('layout')

@section('content')
{{-- Required by the theme installer's file check. No core page type maps a template named
     `post` — `blog.json` resolves a post to `blog` — so this is a standalone alias kept so
     a future POST type, or a shop pointing a page here, renders rather than falling through
     to the bare layout. It is not an @include of pages.blog: that view extends the layout
     itself, and including it would nest the whole document. --}}
<div class="saffron-container saffron-section">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @php
            $loc = $locale ?? app()->getLocale();
            $postTitle = is_array($page->title ?? '')
                ? ($page->title[$loc] ?? current($page->title))
                : ($page->title ?? '');
            $postBody = is_array($page->description ?? '')
                ? ($page->description[$loc] ?? current($page->description))
                : ($page->description ?? '');
        @endphp
        <article>
            @if($postTitle)
                <h1 class="saffron-section-title mb-3">{{ $postTitle }}</h1>
            @endif
            @if($postBody)
                <p class="saffron-section-lede">{{ strip_tags($postBody) }}</p>
            @endif
        </article>
    @endif
</div>
@endsection
