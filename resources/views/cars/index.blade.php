@extends('layouts.marketplace')

@section('content')
<!-- Sticky Mobile Bar -->
<div class="lg:hidden sticky top-0 z-30 bg-white border-b border-gray-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 py-2 flex items-center justify-between">
        <button
            id="mobile-filters-btn"
            class="flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg font-medium flex-shrink-0 text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z" />
            </svg>
            Filtros
        </button>
        <select name="sort" onchange="window.location.href='{{ url()->current() }}?sort=' + this.value" class="px-2 py-1.5 border border-gray-300 rounded-lg text-gray-700 text-xs">
            <option value="newest" {{ request('sort') == 'newest' ? 'selected' : '' }}>Más recientes</option>
            <option value="price_low" {{ request('sort') == 'price_low' ? 'selected' : '' }}>Precio: menor a mayor</option>
            <option value="price_high" {{ request('sort') == 'price_high' ? 'selected' : '' }}>Precio: mayor a menor</option>
        </select>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-sm text-gray-600 mb-6">
        <a href="/" class="hover:text-gray-900">Inicio</a>
        <span>></span>
        <span>{{ isset($slugData) ? $slugData['title'] : (request('brand') ?? 'Autos') }}</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-6">
        <!-- Sidebar Filters -->
        <aside class="hidden lg:block">
            <div class="space-y-4">
                @include('cars._filters')
            </div>
        </aside>

        <!-- Main Content -->
        <div>
            <!-- Header Section -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">{{ isset($slugData) ? $slugData['title'] : (request('brand') ?? 'Autos') }}</h1>
                    <p class="text-gray-600 mt-1">{{ $cars->total() }} resultados encontrados</p>
                </div>
                <div class="hidden lg:block">
                    <select name="sort" onchange="window.location.href='{{ url()->current() }}?sort=' + this.value" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700">
                        <option value="newest" {{ request('sort') == 'newest' ? 'selected' : '' }}>Más recientes</option>
                        <option value="price_low" {{ request('sort') == 'price_low' ? 'selected' : '' }}>Precio: menor a mayor</option>
                        <option value="price_high" {{ request('sort') == 'price_high' ? 'selected' : '' }}>Precio: mayor a menor</option>
                    </select>
                </div>
            </div>

            <!-- Cars List -->
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_300px] gap-6 mb-8">
                <div>
                    @forelse($cars as $car)
                    <a href="/auto/{{ $car->id }}" class="block mb-4">
                    <div class="flex flex-col bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition p-4">
                        <!-- Title -->
                        <h3 class="font-bold text-lg text-gray-900 mb-2 w-full line-clamp-2">{{ $car->title }}</h3>

                        <div class="flex">
                            <!-- Image -->
                            <div class="flex-shrink-0 w-36 h-36 max-w-[150px] max-h-[150px] bg-gray-200 overflow-hidden rounded-lg">
                                @if($car->image_url)
                                    <img src="{{ $car->image_url }}" alt="{{ $car->title }}" class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex items-center justify-center bg-gray-300">
                                        <svg class="w-12 h-12 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z"></path>
                                        </svg>
                                    </div>
                                @endif
                            </div>

                            <!-- Content -->
                            <div class="ml-4 flex-1">
                                <p class="text-2xl font-bold text-gray-900 mb-3">
                                    ${{ number_format($car->price, 0, ',', '.') }}
                                </p>
                                <p class="text-sm text-gray-600 mb-4 line-clamp-2">
                                    {{ $car->brand }} {{ $car->model }} - {{ $car->location }} - {{ $car->year }} - {{ $car->transmission }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ $car->kilometers }} kilómetros · Condición: {{ ucfirst($car->condition) }}
                                </p>
                            </div>
                        </div>
                    </div>
                    </a>
                    @empty
                    <div class="py-12 text-center">
                        <p class="text-gray-600 text-lg">No hay autos que coincidan con tus filtros.</p>
                    </div>
                    @endforelse
                </div>

                <!-- Advertisement Column -->
                <div class="hidden lg:block">
                    <div class="bg-gray-100 h-full rounded-lg shadow p-4">
                        <!-- Placeholder for advertisement -->
                        <p class="text-gray-500 text-center">Espacio publicitario</p>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            @if($cars->hasPages())
            <div class="flex justify-center items-center gap-2 mb-8">
                {{ $cars->appends(request()->query())->links() }}
            </div>
            @endif

            <!-- Pagination Design -->
            <div class="flex justify-center items-center gap-2 mt-8 px-6">
                <button class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">Anterior</button>
                <div class="hidden lg:flex items-center gap-1">
                    <button class="px-3 py-1 bg-[#008bea] text-white rounded-lg">1</button>
                    <button class="px-3 py-1 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">2</button>
                    <button class="px-3 py-1 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">3</button>
                    <span class="px-3 py-1 text-gray-500">...</span>
                    <button class="px-3 py-1 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">10</button>
                </div>
                <button class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">Siguiente</button>
            </div>
        </div>
    </div>

    <!-- Filters Modal -->
    <div
        id="mobile-filters-modal"
        class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-end justify-center">
        <div class="bg-white w-full max-w-md rounded-t-2xl shadow-lg flex flex-col max-h-[90vh]">
            <!-- Header fijo -->
            <div class="flex items-center justify-between px-6 pt-5 pb-3 border-b border-gray-200 flex-shrink-0">
                <h2 class="text-lg font-bold">Filtros</h2>
                <button
                    id="close-filters-btn"
                    class="text-gray-400 hover:text-gray-700 text-3xl leading-none">
                    &times;
                </button>
            </div>
            <!-- Contenido scrollable -->
            <div class="overflow-y-auto flex-1 px-6 py-4">
                <form method="GET" action="{{ url()->current() }}" id="mobile-filters-form" class="space-y-6">
                    <input type="hidden" name="brand" id="modal-brand-input" value="{{ request('brand') }}">
                    <input type="hidden" name="model" id="modal-model-input" value="{{ request('model') }}">
                    <input type="hidden" name="location" id="modal-location-input" value="{{ request('location') }}">
                    <input type="hidden" name="city" id="modal-city-input" value="{{ request('city') }}">
                    @include('cars._filters')
                </form>
            </div>
            <!-- Footer fijo -->
            <div class="px-6 py-4 border-t border-gray-200 flex-shrink-0">
                <button type="submit" form="mobile-filters-form" class="w-full bg-[#008bea] hover:bg-[#007acc] text-white py-3 rounded-lg font-medium">
                    Aplicar filtros
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const filtersBtn = document.getElementById('mobile-filters-btn');
            const filtersModal = document.getElementById('mobile-filters-modal');
            const closeFiltersBtn = document.getElementById('close-filters-btn');

            filtersBtn.addEventListener('click', () => {
                filtersModal.classList.remove('hidden');
            });

            closeFiltersBtn.addEventListener('click', () => {
                filtersModal.classList.add('hidden');
            });

            // Handle filter links with data-uri
            document.querySelectorAll('.filter-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const uri = link.getAttribute('data-uri');
                    const inModal = link.closest('#mobile-filters-form');

                    if (inModal) {
                        // En modal: actualizar input hidden y marcar seleccionado
                        const url = new URL(uri, window.location.origin);
                        const params = url.searchParams;

                        if (params.has('brand')) {
                            document.getElementById('modal-brand-input').value = params.get('brand');
                        }
                        if (params.has('model')) {
                            document.getElementById('modal-model-input').value = params.get('model');
                        }
                        if (params.has('location')) {
                            document.getElementById('modal-location-input').value = params.get('location');
                        }
                        if (params.has('city')) {
                            document.getElementById('modal-city-input').value = params.get('city');
                        }

                        // Actualizar visualización
                        link.closest('ul').querySelectorAll('.filter-link').forEach(l => l.classList.remove('font-semibold'));
                        link.classList.add('font-semibold');
                    } else {
                        // En sidebar desktop: navegar inmediatamente
                        window.location.href = uri;
                    }
                });
            });

            // Handle select dropdowns - navigate immediately on desktop sidebar
            document.querySelectorAll('select[name^="price_"], select[name^="year_"], select[name^="km_"]').forEach(select => {
                const inModal = select.closest('#mobile-filters-form');

                if (!inModal) {
                    // En sidebar desktop: navegar al cambiar
                    select.addEventListener('change', () => {
                        const form = document.createElement('form');
                        form.method = 'GET';
                        form.action = '{{ url()->current() }}';

                        // Copiar todos los query params actuales
                        const params = new URLSearchParams(window.location.search);
                        params.set(select.name, select.value);

                        params.forEach((value, key) => {
                            if (value) {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = key;
                                input.value = value;
                                form.appendChild(input);
                            }
                        });

                        document.body.appendChild(form);
                        form.submit();
                    });
                }
            });
        });
    </script>
</div>
@endsection
