@extends('layouts.marketplace')

@section('content')
<!-- Hero Section -->
<div class="bg-gradient-to-b from-blue-50 to-white py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">{{ number_format($totalCars, 0, ',', '.') }} Autos</h1>
            <p class="text-lg text-gray-600">Encuentra el auto perfecto para ti</p>
        </div>

        <!-- Search Bar -->
        <div class="max-w-3xl mx-auto">
            <form action="/search" method="GET" class="relative">
                <input
                    type="text"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="Buscar por marca, modelo, año..."
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
        <a href="/ford-ranger" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Ford Ranger</a>
        <a href="/honda-accord" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Honda Accord</a>
        <a href="/bmw-320" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">BMW 320</a>
        <a href="/toyota-corolla" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Toyota Corolla</a>
        <a href="/chevrolet-aveo" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Chevrolet Aveo</a>
        <a href="/mazda-3" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Mazda 3</a>
        <a href="/nissan-sentra" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Nissan Sentra</a>
        <a href="/hyundai-tucson" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:border-[#008bea] hover:text-[#008bea] transition">Hyundai Tucson</a>
    </div>
</div>

<!-- Browse by Brand -->
<div class="bg-gray-50 py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Buscar por Marca</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            @foreach(['Alfa Romeo', 'Audi', 'BMW', 'Chevrolet', 'Ford', 'Honda', 'Hyundai', 'Kia', 'Mazda', 'Mercedes-Benz', 'Nissan', 'Toyota', 'Volkswagen', 'Volvo', 'Mitsubishi', 'Suzuki', 'Jeep', 'Subaru'] as $brand)
            <a href="/{{ strtolower(str_replace([' ', '-'], '-', $brand)) }}" class="bg-white p-4 rounded-lg border border-gray-200 hover:border-[#008bea] hover:shadow-md transition text-center">
                <p class="font-medium text-gray-900">{{ $brand }}</p>
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
        <a href="/autos-{{ strtolower($city) }}" class="text-[#008bea] hover:underline">{{ $city }}</a>
        @endforeach
    </div>
</div>

<!-- Recent Popular Searches -->
<div class="bg-gray-50 py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Búsquedas Recientes Populares</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <a href="/toyota-corolla-quito" class="text-[#008bea] hover:underline">Toyota Corolla en Quito</a>
            <a href="/chevrolet-aveo-guayaquil" class="text-[#008bea] hover:underline">Chevrolet Aveo en Guayaquil</a>
            <a href="/honda-civic-cuenca" class="text-[#008bea] hover:underline">Honda Civic en Cuenca</a>
            <a href="/ford-ranger-quito" class="text-[#008bea] hover:underline">Ford Ranger en Quito</a>
            <a href="/mazda-3-guayaquil" class="text-[#008bea] hover:underline">Mazda 3 en Guayaquil</a>
            <a href="/nissan-sentra-quito" class="text-[#008bea] hover:underline">Nissan Sentra en Quito</a>
            <a href="/hyundai-tucson-cuenca" class="text-[#008bea] hover:underline">Hyundai Tucson en Cuenca</a>
            <a href="/toyota-rav4-quito" class="text-[#008bea] hover:underline">Toyota RAV4 en Quito</a>
            <a href="/chevrolet-spark-guayaquil" class="text-[#008bea] hover:underline">Chevrolet Spark en Guayaquil</a>
        </div>
    </div>
</div>

@endsection
