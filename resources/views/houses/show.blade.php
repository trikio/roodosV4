@extends('layouts.marketplace')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-sm text-gray-600 mb-6">
        <a href="/" class="hover:text-gray-900">Inicio</a>
        <span>></span>
        <a href="/casas" class="hover:text-gray-900">Casas</a>
        <span>></span>
        <span>{{ $house->title }}</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_350px] gap-8">
        <!-- Main Content -->
        <div>
            <!-- Images -->
            <div class="bg-gray-200 rounded-lg overflow-hidden mb-6">
                @if($house->image_url)
                    <img src="{{ $house->image_url }}" alt="{{ $house->title }}" class="w-full h-96 object-cover">
                @else
                    <div class="w-full h-96 flex items-center justify-center">
                        <p class="text-gray-400">Sin imagen</p>
                    </div>
                @endif
            </div>

            <!-- Details -->
            <div class="bg-white rounded-lg shadow p-6">
                <h1 class="text-3xl font-bold text-gray-900 mb-4">{{ $house->title }}</h1>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div>
                        <p class="text-sm text-gray-600">Tipo</p>
                        <p class="text-lg font-semibold">{{ $house->type }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Habitaciones</p>
                        <p class="text-lg font-semibold">{{ $house->bedrooms }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Baños</p>
                        <p class="text-lg font-semibold">{{ $house->bathrooms }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Área</p>
                        <p class="text-lg font-semibold">{{ $house->area }}m²</p>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <h2 class="text-xl font-bold mb-2">Ubicación</h2>
                    <p class="text-gray-700">{{ $house->city }}, {{ $house->location }}</p>
                </div>

                <div class="border-t pt-4 mt-4">
                    <h2 class="text-xl font-bold mb-2">Condición</h2>
                    <p class="text-gray-700">{{ ucfirst($house->condition) }}</p>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="bg-white rounded-lg shadow p-6 sticky top-6">
                <p class="text-3xl font-bold text-gray-900 mb-6">${{ number_format($house->price, 0, ',', '.') }}</p>

                <button class="w-full bg-[#008bea] hover:bg-[#007acc] text-white py-3 rounded-lg font-medium mb-3">
                    Contactar vendedor
                </button>

                <button class="w-full border border-[#008bea] text-[#008bea] hover:bg-blue-50 py-3 rounded-lg font-medium">
                    Guardar favorito
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
