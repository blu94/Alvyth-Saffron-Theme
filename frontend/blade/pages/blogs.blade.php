@extends('layout')

@section('content')
{{-- ThemeController injects $posts (paginated) and $blogCategories for this template only. --}}
<div class="saffron-container saffron-section">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @php
            $loc = $locale ?? app()->getLocale();
        @endphp
        <h1 class="saffron-section-title mb-4">{{ __('Stories from the kitchen') }}</h1>

        @if(empty($posts) || $posts->isEmpty())
            <div class="saffron-menu__empty">
                <p class="mb-0">{{ __('Nothing published yet.') }}</p>
            </div>
        @else
            <div class="row g-4">
                @foreach($posts as $post)
                    @php
                        $postTitle = is_array($post->title) ? ($post->title[$loc] ?? current($post->title)) : $post->title;
                        $postSlug  = $post->getTranslation('slug', $loc, false) ?: $post->getTranslation('slug', 'en', false);
                    @endphp
                    <div class="col-12 col-md-6 col-lg-4">
                        <article class="saffron-dish-card h-100">
                            <div class="saffron-dish-card__body">
                                <h2 class="saffron-dish-card__title">
                                    <a href="{{ url('/blogs/' . ltrim((string) $postSlug, '/')) }}">{{ $postTitle }}</a>
                                </h2>
                                <p class="saffron-dish-card__desc mb-0">{{ $post->created_at?->format('j M Y') }}</p>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>

            {{-- Core's shared pager, not `$posts->links()`: Laravel's default paginator view is
                 Tailwind markup whose chevron SVGs take their size from Tailwind classes this
                 theme does not ship, so it rendered a chevron the width of the page (audit A14).
                 The same partial `collection_grid`, `product_grid` and `blog_grid` use. --}}
            <div class="mt-4">
                @include('components.builder.sections.partials.pager', ['paginator' => $posts])
            </div>
        @endif
    @endif
</div>
@endsection
