@extends('layout')

@section('content')
{{-- One menu section as its own page, reached at /collections/{slug}.

     The builder rows belong to the Category record, so "Burgers" can be laid out
     differently from "Drinks" without a template per course. A category with no rows — which
     is every category on a shop nobody has hand-built yet — falls back to CategoryMenu, the
     driver that renders the Menu Sections block an operator would have added, scoped to this
     category. Rows still win wherever they exist, so a custom layout overrides the default
     rather than competing with it, and the "being updated" message this template used to
     print unconditionally now belongs to that component — it is shown for the case it was
     actually written for, a category that genuinely has no dishes. --}}
<div class="saffron-collection">
    <x-theme.component name="Breadcrumbs" />
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <x-theme.component name="CategoryMenu" />
    @endif
</div>
@endsection
