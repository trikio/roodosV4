@extends('layouts.marketplace')

@php
    $canonicalUrl = url('/guias/' . $guide['slug']);
    $breadcrumbItems = [
        ['label' => 'Inicio', 'url' => url('/')],
        ['label' => 'Guías', 'url' => url('/guias')],
        ['label' => $guide['title'], 'url' => $canonicalUrl],
    ];
    $organization = [
        '@type' => 'Organization',
        'name' => 'Roodos',
        'url' => url('/'),
        'logo' => ['@type' => 'ImageObject', 'url' => 'https://autos.roodos.cl/assets/img/roodos.png'],
    ];
    $articleSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $guide['title'],
        'description' => $guide['meta_description'],
        'inLanguage' => $lang,
        'datePublished' => $guide['published_at'],
        'dateModified' => $guide['updated_at'],
        'author' => $organization,
        'publisher' => $organization,
        'mainEntityOfPage' => $canonicalUrl,
    ];
    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($breadcrumbItems)->values()->map(fn ($item, $index) => [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'name' => $item['label'],
            'item' => $item['url'],
        ])->all(),
    ];
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;
@endphp

@section('page_title', $guide['title'])
@section('html_title', $guide['meta_title'])
@section('html_lang', $lang)
@section('meta_description', $guide['meta_description'])
@section('robots_content', 'index,follow')
@section('canonical_url', $canonicalUrl)
@section('head')
<meta property="og:type" content="article">
<meta property="og:site_name" content="Roodos">
<meta property="og:locale" content="{{ str_replace('-', '_', $lang) }}">
<meta property="og:title" content="{{ $guide['meta_title'] }}">
<meta property="og:description" content="{{ $guide['meta_description'] }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="article:published_time" content="{{ $guide['published_at'] }}">
<meta property="article:modified_time" content="{{ $guide['updated_at'] }}">
<script type="application/ld+json">{!! json_encode($articleSchema, $jsonFlags) !!}</script>
<script type="application/ld+json">{!! json_encode($breadcrumbSchema, $jsonFlags) !!}</script>
@endsection

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
    <nav aria-label="Migas de pan" class="flex items-center gap-2 text-sm text-gray-600 mb-6 min-w-0">
        <a href="{{ url('/') }}" class="shrink-0 hover:text-gray-900">Inicio</a>
        <span class="shrink-0">&gt;</span>
        <a href="{{ url('/guias') }}" class="shrink-0 hover:text-gray-900">Guías</a>
        <span class="shrink-0">&gt;</span>
        <span class="truncate">{{ $guide['title'] }}</span>
    </nav>

    <article class="bg-white rounded-xl shadow px-5 py-8 sm:px-10 sm:py-10">
        <div class="guide-measure">
            <header class="mb-8">
                <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">{{ $guide['title'] }}</h1>
                <p class="text-sm text-gray-500 mt-4">
                    Publicado el <time datetime="{{ $guide['published_at'] }}">{{ \Illuminate\Support\Carbon::parse($guide['published_at'])->locale('es')->translatedFormat('j \d\e F \d\e Y') }}</time>
                </p>
            </header>

            <div class="guide-content">
                {!! $guide['html'] !!}
            </div>
        </div>
    </article>

    <section class="mt-8 bg-white rounded-xl shadow px-5 py-8 sm:px-10">
        <h2 class="text-2xl font-bold text-gray-900 mb-2">Busca tu próximo auto en {{ $countryName }}</h2>
        <p class="text-gray-600 mb-6">Compara miles de anuncios de autos usados en un solo lugar.</p>
        <a href="{{ url('/search') }}" class="inline-block bg-[#008bea] hover:bg-[#007acc] text-white px-5 py-3 rounded-lg font-medium mb-8">
            Ver autos usados en {{ $countryName }}
        </a>
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Buscar por marca</h3>
        <div class="flex flex-wrap gap-3">
            @foreach($makeLinks as $link)
            <a href="{{ $link['url'] }}" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">{{ $link['label'] }}</a>
            @endforeach
        </div>
    </section>
</div>
@endsection
