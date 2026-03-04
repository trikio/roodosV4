@extends('layouts.marketplace')

@section('page_title', '25.486 Casas')

@section('content')
<!-- Hero Section -->
<div class="bg-gradient-to-b from-blue-50 to-white py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">@yield('page_title')</h1>
            <p class="text-lg text-gray-600">Encuentra la casa perfecta para ti</p>
        </div>

        <!-- Search Bar -->
        <div class="max-w-3xl mx-auto">
            <form action="{{ route('houses.landing', 'buscar') }}" method="GET" class="relative">
                <input
                    type="text"
                    name="q"
                    placeholder="Buscar por tipo, ubicación, precio..."
                    class="w-full px-6 py-4 pr-32 rounded-lg border-2 border-gray-300 focus:outline-none focus:border-[#008bea] text-lg shadow-sm"
                >
                <button
                    type="submit"
                    class="absolute right-2 top-1/2 transform -translate-y-1/2 bg-[#008bea] hover:bg-[#007acc] text-white px-8 py-3 rounded-lg font-medium transition">
                    Buscar
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Popular Searches -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Búsquedas Populares</h2>
    <div class="flex flex-wrap gap-3">
        <a href="/casa-quito" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Casas en Quito</a>
        <a href="/departamento-guayaquil" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Departamentos en Guayaquil</a>
        <a href="/villa-cuenca" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Villas en Cuenca</a>
        <a href="/casa-manta" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Casas en Manta</a>
        <a href="/departamento-ambato" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Departamentos en Ambato</a>
        <a href="/terreno-santo-domingo" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Terrenos en Santo Domingo</a>
    </div>
</div>

<!-- Browse by Type -->
<div class="bg-gray-50 py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Buscar por Tipo</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach(['Casa', 'Departamento', 'Villa', 'Terreno'] as $type)
            <a href="/{{ strtolower($type) }}" class="bg-white p-4 rounded-lg border border-gray-200 hover:border-[#008bea] hover:shadow-md transition text-center">
                <p class="font-medium text-gray-900">{{ $type }}</p>
            </a>
            @endforeach
        </div>
    </div>
</div>

<!-- Browse by Location -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Buscar por Ubicación</h2>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
        @foreach(['Quito', 'Guayaquil', 'Cuenca', 'Ambato', 'Manta', 'Santo Domingo', 'Portoviejo', 'Machala', 'Loja', 'Ibarra', 'Riobamba', 'Esmeraldas'] as $city)
        <a href="/casas-{{ strtolower($city) }}" class="text-[#008bea] hover:underline">{{ $city }}</a>
        @endforeach
    </div>
</div>

<!-- Recent Popular Searches -->
<div class="bg-gray-50 py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Búsquedas Recientes Populares</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <a href="/casa-quito" class="text-[#008bea] hover:underline">Casa en Quito</a>
            <a href="/departamento-guayaquil" class="text-[#008bea] hover:underline">Departamento en Guayaquil</a>
            <a href="/villa-cuenca" class="text-[#008bea] hover:underline">Villa en Cuenca</a>
            <a href="/casa-manta" class="text-[#008bea] hover:underline">Casa en Manta</a>
            <a href="/departamento-ambato" class="text-[#008bea] hover:underline">Departamento en Ambato</a>
            <a href="/terreno-santo-domingo" class="text-[#008bea] hover:underline">Terreno en Santo Domingo</a>
            <a href="/casa-portoviejo" class="text-[#008bea] hover:underline">Casa en Portoviejo</a>
            <a href="/villa-machala" class="text-[#008bea] hover:underline">Villa en Machala</a>
            <a href="/departamento-loja" class="text-[#008bea] hover:underline">Departamento en Loja</a>
        </div>
    </div>
</div>

@endsection
