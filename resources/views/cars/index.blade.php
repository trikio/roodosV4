@extends('layouts.marketplace')

@php
    $pageTitle = isset($slugData) ? $slugData['title'] : (!empty($searchQuery) ? ucfirst($searchQuery) : 'Autos');
    $countryCodeForCurrency = strtoupper((string) (request()->route('country') ?: ($country ?? '')));
    if ($countryCodeForCurrency === '') {
        $host = request()->getHost();
        if (preg_match('/^autos\.roodos\.([^.]+)$/', $host, $matches)) {
            $countryCodeForCurrency = strtoupper($matches[1]);
        }
    }
    $currencySymbol = config('countries.currency.' . $countryCodeForCurrency, '$');
    $metaDescription = $pageTitle;
    if (isset($slugData)) {
        $sampleTitles = $cars->take(3)->pluck('title')->filter()->implode(', ');
        $metaDescription = $cars->total() . ' anuncios para ' . $pageTitle . '.';
        if ($sampleTitles !== '') {
            $metaDescription .= ' ' . $sampleTitles;
        }
    }
    $adsKeyword = trim((string) ($searchQuery ?? ''));
    if ($adsKeyword === '' && isset($slugData['title'])) {
        $adsKeyword = trim((string) $slugData['title']);
    }
    if ($adsKeyword === '') {
        $adsKeyword = trim((string) request('q', ''));
    }
    $currentOrder = request('order');
    if (empty($currentOrder)) {
        $currentOrder = match (request('sort')) {
            'price_low' => 'priceasc',
            'price_high' => 'pricedesc',
            default => '',
        };
    }
@endphp

@section('page_title', $pageTitle)
@section('meta_description', $metaDescription)

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
        <select name="order" class="sort-order-select px-2 py-1.5 border border-gray-300 rounded-lg text-gray-700 text-xs">
            <option value="" {{ $currentOrder === '' ? 'selected' : '' }}>Más recientes</option>
            <option value="priceasc" {{ $currentOrder === 'priceasc' ? 'selected' : '' }}>Precio: menor a mayor</option>
            <option value="pricedesc" {{ $currentOrder === 'pricedesc' ? 'selected' : '' }}>Precio: mayor a menor</option>
        </select>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-sm text-gray-600 mb-6">
        <a href="/" class="hover:text-gray-900">Inicio</a>
        <span>></span>
        <span>{{ $pageTitle }}</span>
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
                    <h1 class="text-3xl font-bold text-gray-900">{{ $pageTitle }}</h1>
                    <p class="text-gray-600 mt-1">{{ $cars->total() }} resultados encontrados</p>
                </div>
                <div class="hidden lg:block">
                    <select name="order" class="sort-order-select px-4 py-2 border border-gray-300 rounded-lg text-gray-700">
                        <option value="" {{ $currentOrder === '' ? 'selected' : '' }}>Más recientes</option>
                        <option value="priceasc" {{ $currentOrder === 'priceasc' ? 'selected' : '' }}>Precio: menor a mayor</option>
                        <option value="pricedesc" {{ $currentOrder === 'pricedesc' ? 'selected' : '' }}>Precio: mayor a menor</option>
                    </select>
                </div>
            </div>

            <!-- Cars List -->
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_300px] gap-6 mb-8">
                <div>
                    <div id="afscontainer1" class="mb-4"></div>
                    <script type="text/javascript" charset="utf-8">
                        var pageOptions = {
                            "pubId": "partner-pub-4474623749606512",
                            "query": @json($adsKeyword),
                            "styleId": "6494563446",
                            "adsafe": "high"
                        };

                        var adblock1 = {
                            "container": "afscontainer1",
                            "number": 2
                        };

                        var adblock2 = {
                            "container": "afscontainer2",
                            "number": 4
                        };

                        _googCsa('ads', pageOptions, adblock1, adblock2);
                    </script>
                    @forelse($cars as $car)
                    <a href="/auto/{{ $car->id }}" class="block mb-4 js-result-link">
                    <div class="flex flex-col bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition p-4">
                        <!-- Title -->
                        <h2 class="font-bold text-lg text-gray-900 mb-2 w-full line-clamp-2">{{ $car->title }}</h2>

                        <div class="flex">
                            <!-- Image -->
                            <div class="flex-shrink-0 w-36 h-36 max-w-[150px] max-h-[150px] bg-gray-200 overflow-hidden rounded-lg">
                                @if($car->image_url)
                                    @if($loop->first)
                                        <img
                                            src="{{ $car->image_url }}"
                                            alt="{{ $car->title }}"
                                            class="w-full h-full object-cover"
                                            loading="eager"
                                            decoding="async"
                                            fetchpriority="high"
                                        >
                                    @else
                                        <img
                                            src="data:image/gif;base64,R0lGODlhAQABAAAAACw="
                                            data-src="{{ $car->image_url }}"
                                            alt="{{ $car->title }}"
                                            class="w-full h-full object-cover lazy-car-image"
                                            loading="lazy"
                                            decoding="async"
                                            fetchpriority="low"
                                        >
                                    @endif
                                @else
                                    <div class="w-full h-full flex items-center justify-center bg-gray-100">
                                        <img
                                            src="https://autos.roodos.cl/assets/img/cars_logo_v2.png"
                                            alt="Autos Roodos"
                                            class="w-16 h-16 object-contain grayscale opacity-35"
                                            onerror="this.onerror=null;this.src='{{ asset('assets/img/cars_logo_v2.png') }}';"
                                        >
                                    </div>
                                @endif
                            </div>

                            <!-- Content -->
                            <div class="ml-4 flex-1">
                                <p class="text-2xl font-bold text-gray-900 mb-3">
                                    {{ $currencySymbol }} {{ number_format($car->price, 0, ',', '.') }}
                                </p>
                                @php
                                    $carPrimaryLabel = trim(implode(' ', array_filter([$car->brand, $car->model])));
                                    $carMetaParts = array_values(array_filter([
                                        $carPrimaryLabel,
                                        $car->location,
                                        $car->year,
                                        $car->transmission,
                                    ], fn ($value) => filled($value)));
                                @endphp
                                <p class="text-sm text-gray-600 mb-4 line-clamp-2">
                                    {{ implode(' - ', $carMetaParts) }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ $car->kilometers }} kilómetros · Condición: {{ ucfirst($car->condition) }}
                                </p>
                                @if(!empty($car->nexo_id))
                                <p class="text-xs text-gray-400 mt-2">
                                    Fuente: {{ $car->nexo_id }}
                                </p>
                                @endif
                            </div>
                        </div>
                    </div>
                    </a>
                    @empty
                    <div class="py-12 text-center">
                        <p class="text-gray-600 text-lg">No hay autos que coincidan con tus filtros.</p>
                    </div>
                    @endforelse

                    <div id="afscontainer2" class="mt-6"></div>

                    <!-- Pagination -->
                    @if($cars->hasPages())
                    <div class="mt-8">
                        {{ $cars->onEachSide(0)->appends(request()->query())->links('vendor.pagination.tailwind') }}
                    </div>
                    @endif
                </div>

                <!-- Advertisement Column -->
                <div class="hidden lg:block">
                    <div class="sticky top-6">
                        <div class="w-[300px] h-[600px] rounded-lg border border-gray-200 shadow overflow-hidden bg-white flex items-center justify-center">
                            <ins
                                class="adsbygoogle js-lazy-adsense"
                                style="display:inline-block;width:300px;height:600px"
                                data-ad-client="ca-pub-4474623749606512"
                                data-ad-slot="4621188394"
                            ></ins>
                        </div>
                    </div>
                </div>
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
                <span class="text-lg font-bold">Filtros</span>
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
            const isDesktopViewport = () => window.matchMedia('(min-width: 1024px)').matches;

            const syncResultLinksTarget = () => {
                document.querySelectorAll('.js-result-link').forEach((link) => {
                    if (isDesktopViewport()) {
                        link.setAttribute('target', '_blank');
                        link.setAttribute('rel', 'noopener noreferrer');
                    } else {
                        link.removeAttribute('target');
                        link.removeAttribute('rel');
                    }
                });
            };

            syncResultLinksTarget();
            window.addEventListener('resize', syncResultLinksTarget);
            window.addEventListener('orientationchange', syncResultLinksTarget);

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
            document.querySelectorAll('select[name^="min_"], select[name^="max_"]').forEach(select => {
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

            // Lazy load robusto con IntersectionObserver.
            const lazyImages = document.querySelectorAll('img.lazy-car-image[data-src]');
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries, obs) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) {
                            return;
                        }

                        const img = entry.target;
                        const dataSrc = img.getAttribute('data-src');
                        if (dataSrc) {
                            img.setAttribute('src', dataSrc);
                            img.removeAttribute('data-src');
                        }
                        img.classList.remove('lazy-car-image');
                        obs.unobserve(img);
                    });
                }, {
                    rootMargin: '250px 0px',
                    threshold: 0.01
                });

                lazyImages.forEach((img) => observer.observe(img));
            } else {
                // Fallback para navegadores sin IntersectionObserver.
                lazyImages.forEach((img) => {
                    const dataSrc = img.getAttribute('data-src');
                    if (dataSrc) {
                        img.setAttribute('src', dataSrc);
                        img.removeAttribute('data-src');
                    }
                    img.classList.remove('lazy-car-image');
                });
            }

            // Handle sorting while preserving current filters.
            document.querySelectorAll('.sort-order-select').forEach(select => {
                select.addEventListener('change', () => {
                    const params = new URLSearchParams(window.location.search);
                    const value = select.value;

                    if (value) {
                        params.set('order', value);
                    } else {
                        params.delete('order');
                    }

                    // Remove legacy sort param and reset pagination on new ordering.
                    params.delete('sort');
                    params.delete('page');

                    const query = params.toString();
                    window.location.href = query
                        ? '{{ url()->current() }}?' + query
                        : '{{ url()->current() }}';
                });
            });

            // Expand/collapse long filter lists (desktop sidebar and mobile modal).
            document.querySelectorAll('.toggle-filter-options').forEach((button) => {
                button.addEventListener('click', () => {
                    const group = button.closest('[data-filter-group]');
                    if (!group) {
                        return;
                    }

                    const extraItems = group.querySelectorAll('.filter-extra-option');
                    const isExpanded = button.dataset.expanded === 'true';

                    extraItems.forEach((item) => item.classList.toggle('hidden', isExpanded));
                    button.dataset.expanded = isExpanded ? 'false' : 'true';
                    button.textContent = isExpanded
                        ? button.dataset.openText
                        : button.dataset.closeText;
                });
            });
        });
    </script>
</div>
@endsection
