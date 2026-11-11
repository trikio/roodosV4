@extends('layouts.marketplace')

@php
    $pageHeading = 'Guías para comprar auto en ' . $countryName;
@endphp

@section('page_title', $pageHeading)
@section('html_title', $pageHeading . ' | Roodos')
@section('html_lang', $lang)
@section('meta_description', 'Guías de Roodos para comprar un auto en ' . $countryName . ': cómo trabajan quienes venden autos y qué revisar antes de decidir.')
@section('robots_content', 'index,follow')
@section('canonical_url', url('/guias'))

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
    <nav aria-label="Migas de pan" class="flex items-center gap-2 text-sm text-gray-600 mb-6">
        <a href="{{ url('/') }}" class="hover:text-gray-900">Inicio</a>
        <span>&gt;</span>
        <span>Guías</span>
    </nav>

    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-8">{{ $pageHeading }}</h1>

    <div class="space-y-4">
        @foreach($guides as $guide)
        <article class="bg-white rounded-xl shadow p-6 hover:shadow-lg transition">
            <h2 class="text-xl font-bold text-gray-900 mb-2">
                <a href="{{ url('/guias/' . $guide['slug']) }}" class="hover:text-[#008bea]">{{ $guide['title'] }}</a>
            </h2>
            <p class="text-gray-600 mb-4">{{ $guide['meta_description'] }}</p>
            <a href="{{ url('/guias/' . $guide['slug']) }}" class="text-[#008bea] font-medium hover:underline">Leer la guía</a>
        </article>
        @endforeach
    </div>
</div>
@endsection
