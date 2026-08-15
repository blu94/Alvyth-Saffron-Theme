@extends('layout')

@section('content')
{{-- A single post. `blog.json` maps the BLOG page type to this template, and the
     /blogs/{slug} resource path resolves to it too. --}}
<x-theme.component name="Breadcrumbs" />
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
