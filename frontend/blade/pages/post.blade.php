@extends('layout')

@section('content')
{{-- Required by the theme installer's file check. No core page type maps a template named
     `post` — `blog.json` resolves a post to `blog` — so this is a standalone alias kept so
     a future POST type, or a shop pointing a page here, renders rather than falling through
     to the bare layout. It is not an @include of pages.blog: that view extends the layout
     itself, and including it would nest the whole document. The body below is pages.blog's,
     kept identical by hand — change both. --}}
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
            // The furniture below reads Post relations. `blog.json` can also route a Page of
            // type BLOG here, and a Page has no assets or neighbours — so guard on the model.
            $isPost    = ($page ?? null) instanceof \App\Models\Post;
            $thumbnail = $isPost ? $page->assets->where('usage', 'BLOG_THUMBNAIL')->first() : null;
            $author    = $isPost ? $page->author?->name : null;
            $prevPost  = $isPost ? $page->prev_post : null;
            $nextPost  = $isPost ? $page->next_post : null;
            $postUrl   = fn ($post) => url('/blogs/' . ltrim((string) ($post->getTranslation('slug', $loc, false) ?: $post->getTranslation('slug', 'en', false)), '/'));
        @endphp
        <article class="saffron-post">
            <header class="saffron-post__head">
                @if($postTitle)
                    <h1 class="saffron-section-title mb-2">{{ $postTitle }}</h1>
                @endif
                @if($isPost)
                    <p class="saffron-post__meta">
                        @if($author)
                            <span>{{ __('By :author', ['author' => $author]) }}</span>
                            <span class="saffron-post__meta-sep" aria-hidden="true">·</span>
                        @endif
                        <time datetime="{{ $page->created_at?->toDateString() }}">{{ $page->created_at?->format('j F Y') }}</time>
                    </p>
                @endif
            </header>

            @if($thumbnail)
                <figure class="saffron-post__hero">
                    <img src="{{ $thumbnail->path }}" alt="{{ $postTitle }}" loading="lazy">
                </figure>
            @endif

            @if($postBody)
                <p class="saffron-section-lede saffron-post__lede">{{ strip_tags($postBody) }}</p>
            @endif

            {{-- Legacy content blocks. A post migrated from an Ella shop keeps its body in
                 `data` as `html` / `text` / `image` blocks rather than in builder rows; Ella's
                 post template renders them and this one silently dropped them (audit A16). The
                 loop is Ella's, so a post reads the same under either theme. --}}
            @if(!empty($page->data) && is_array($page->data))
                <div class="saffron-post__body">
                    @foreach($page->data as $block)
                        @if(($block['type'] ?? '') === 'html')
                            <div class="saffron-post__block">{!! $block['content'] ?? '' !!}</div>
                        @elseif(($block['type'] ?? '') === 'text')
                            <p class="saffron-post__block">{{ $block['text'] ?? '' }}</p>
                        @elseif(($block['type'] ?? '') === 'image')
                            <img src="{{ $block['url'] ?? '' }}" alt="" class="saffron-post__block saffron-post__image" loading="lazy">
                        @endif
                    @endforeach
                </div>
            @endif

            @if($prevPost || $nextPost)
                <nav class="saffron-post__nav" aria-label="{{ __('More stories') }}">
                    @if($prevPost)
                        <a href="{{ $postUrl($prevPost) }}" class="saffron-btn saffron-btn--outline" rel="prev">{{ __('Previous story') }}</a>
                    @else
                        <span></span>
                    @endif
                    @if($nextPost)
                        <a href="{{ $postUrl($nextPost) }}" class="saffron-btn saffron-btn--outline" rel="next">{{ __('Next story') }}</a>
                    @endif
                </nav>
            @endif
        </article>
    @endif
</div>

{{-- Kept identical to pages.blog by hand, as the note at the top of this file requires. --}}
<x-theme.component name="PostComments" />
@endsection
