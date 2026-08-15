@extends('layout')

@section('content')
    @include('components.builder.engine', ['rows' => $page->rows])
@endsection
