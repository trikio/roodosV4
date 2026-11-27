@extends('layouts.marketplace')

@php
    $currencySymbol = config('countries.currency.' . strtoupper($country), '$');
    $money = fn (int $value) => $currencySymbol . ' ' . number_format($value, 0, ',', '.');
    $place = implode(', ', array_filter([$house->city, $house->location]));

    // "Departamento" + "Venta" -> "departamentos en venta"; without a type, "propiedades".
    $plural = function (string $word): string {
        $word = mb_strtolower(trim($word));
        if ($word === '') {
            return 'propiedades';
        }
        return preg_match('/[aeiouáéó]$/u', $word) ? $word . 's' : $word . 'es';
    };
    $kind = trim($plural($house->type) . ($house->operation !== '' ? ' en ' . mb_strtolower($house->operation) : ''));

    $specs = array_filter([
        'Operación' => $house->operation,
        'Tipo' => $house->type,
        'Superficie' => $house->size > 0 ? number_format($house->size, 0, ',', '.') . ' m²' : null,
        'Dormitorios' => $house->rooms ?: null,
        'Baños' => $house->bath ?: null,
        'Ubicación' => $place,
        // Chilean ads in UF ("22") would give $0 per m².
        'Precio por m²' => $house->price >= $house->size && \App\Support\HousePriceStats::validSize($house->size)
            ? $money((int) round($house->price / $house->size)) : null,
    ], fn ($value) => filled($value));

    $verdictLabel = $verdict ? match ($verdict[1]) {
        'below' => ['Bajo lo típico de la zona', 'text-green-700 bg-green-50'],
        'above' => ['Sobre lo típico de la zona', 'text-amber-700 bg-amber-50'],
        default => ['En lo típico de la zona', 'text-gray-700 bg-gray-100'],
    } : null;

    $breadcrumbItems = array_values(array_filter([
        ['label' => 'Inicio', 'url' => url('/')],
        $landing ? ['label' => $landing['title'], 'url' => $landing['url']]
            : ($area ? ['label' => ucfirst($kind) . ' en ' . $area['name'], 'url' => $searchUrl] : null),
        ['label' => $house->title, 'url' => null],
    ]));

    $description = \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $house->details), 700);
    $detailSlot = config('services.adsense.detail_slot');
@endphp

@section('page_title', $house->title)
@section('html_title', $house->title . ($house->price > 0 ? ' - ' . $money($house->price) : '') . ' - Roodos Casas')
@section('meta_description', trim($house->title . ' ' . implode(' · ', array_slice($specs, 0, 3))))
@section('robots_content', 'noindex,follow')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-4">
        <ol class="flex flex-wrap items-center gap-1">
            @foreach($breadcrumbItems as $item)
                <li class="flex items-center gap-1 min-w-0">
                    @if(!$loop->first)<span aria-hidden="true">›</span>@endif
                    @if($item['url'])
                        <a href="{{ $item['url'] }}" class="hover:text-[#008bea] hover:underline">{{ $item['label'] }}</a>
                    @else
                        <span class="text-gray-700 truncate max-w-[16rem]">{{ $item['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_300px] gap-6">
        <div class="min-w-0 space-y-6">
            {{-- Ficha --}}
            <section class="bg-white rounded-lg shadow p-5 sm:p-6">
                <div class="flex flex-col sm:flex-row gap-5">
                    <div class="flex-shrink-0 w-36 h-36 bg-gray-100 overflow-hidden rounded-lg">
                        @if($house->image_url)
                            <img src="{{ $house->image_url }}" alt="{{ $house->title }}" class="w-full h-full object-cover" fetchpriority="high">
                        @endif
                    </div>

                    <div class="flex-1 min-w-0">
                        <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ $house->title }}</h1>
                        @if($house->price > 0)
                            <p class="text-3xl font-bold text-gray-900 mb-2">{{ $money($house->price) }}</p>
                        @endif
                        @if($verdictLabel)
                            <span class="inline-block text-xs font-medium px-2 py-1 rounded {{ $verdictLabel[1] }} mb-3">{{ $verdictLabel[0] }}</span>
                        @endif
                        <p class="text-sm text-gray-600">
                            {{ implode(' · ', array_filter([
                                $house->size > 0 ? number_format($house->size, 0, ',', '.') . ' m²' : null,
                                $house->rooms ? $house->rooms . ' dorm.' : null,
                                $house->bath ? $house->bath . ' baños' : null,
                                $place,
                            ])) }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-3">
                    <a
                        href="{{ $targetUrl }}"
                        target="_blank"
                        rel="nofollow noopener noreferrer sponsored"
                        class="js-outbound inline-flex justify-center items-center gap-2 bg-[#008bea] hover:bg-[#007acc] text-white px-6 py-3 rounded-lg font-semibold"
                    >
                        Ver aviso en {{ $source }}
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5M19 5l-9 9M5 7v12h12v-5" />
                        </svg>
                    </a>
                    <p class="text-xs text-gray-500">
                        Fotos, contacto y estado actual del aviso en {{ $source }}. Roodos no vende ni intermedia.
                    </p>
                </div>
            </section>

            {{-- Características --}}
            @if(count($specs) > 0)
            <section class="bg-white rounded-lg shadow p-5 sm:p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Características</h2>
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-4">
                    @foreach($specs as $label => $value)
                        <div>
                            <dt class="text-xs text-gray-500">{{ $label }}</dt>
                            <dd class="text-sm font-medium text-gray-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if($description !== '')
                    <p class="mt-5 text-sm text-gray-700 leading-relaxed">{{ $description }}</p>
                @endif
            </section>
            @endif

            {{-- Precios de la zona --}}
            @if($stats)
            <section class="bg-white rounded-lg shadow p-5 sm:p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-1">Precios de {{ $kind }} en {{ $area['name'] }}</h2>
                <p class="text-sm text-gray-500 mb-4">Según {{ number_format($stats['total'], 0, ',', '.') }} avisos publicados en Roodos. Valores medianos: la mitad de los avisos está por debajo y la otra mitad por encima.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-xs text-gray-500">Precio típico</p>
                        <p class="font-semibold text-gray-900">{{ $money($stats['price']) }}</p>
                    </div>
                    @if($stats['per_m2'])
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-xs text-gray-500">Precio por m²</p>
                        <p class="font-semibold text-gray-900">{{ $money($stats['per_m2']['median']) }}</p>
                    </div>
                    @endif
                    @if($stats['size'])
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-xs text-gray-500">Superficie típica</p>
                        <p class="font-semibold text-gray-900">{{ number_format($stats['size'], 0, ',', '.') }} m²</p>
                    </div>
                    @endif
                </div>
                @if($stats['per_m2'])
                    <p class="text-sm text-gray-600 mt-4">
                        La mitad de los avisos de {{ $kind }} en {{ $area['name'] }} se publica entre
                        <strong>{{ $money($stats['per_m2']['low']) }}</strong> y <strong>{{ $money($stats['per_m2']['high']) }}</strong> por m².
                        @if($verdict && $verdict[0] === 'per_m2' && \App\Support\HousePriceStats::validSize($house->size))
                            Este aviso pide {{ $money((int) round($house->price / $house->size)) }} por m².
                        @endif
                    </p>
                @endif
                @if($searchUrl)
                    <a href="{{ $searchUrl }}" class="inline-block mt-4 text-sm text-[#008bea] hover:underline">{{ $landing ? 'Ver ' . mb_strtolower($landing['title']) : 'Ver más ' . $kind . ' en ' . $area['name'] }}</a>
                @endif
            </section>
            @endif

            @if($detailSlot)
            <div class="min-h-[100px]">
                <ins
                    class="adsbygoogle js-adsense"
                    style="display:block"
                    data-ad-client="ca-pub-4474623749606512"
                    data-ad-slot="{{ $detailSlot }}"
                    data-ad-format="auto"
                    data-full-width-responsive="true"
                ></ins>
            </div>
            @endif

            {{-- Parecidas --}}
            @if(count($similar) > 0)
            <section>
                <h2 class="text-lg font-bold text-gray-900 mb-4">Propiedades parecidas en {{ $area['name'] }}</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach($similar as $item)
                        <a href="/casa/{{ $item->id }}" rel="nofollow" class="flex gap-3 bg-white rounded-lg shadow hover:shadow-lg transition p-3">
                            <div class="flex-shrink-0 w-24 h-24 bg-gray-100 overflow-hidden rounded">
                                @if($item->image_url)
                                    <img src="{{ $item->image_url }}" alt="{{ $item->title }}" class="w-full h-full object-cover" loading="lazy" decoding="async">
                                @endif
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 line-clamp-2">{{ $item->title }}</p>
                                <p class="font-bold text-gray-900 mt-1">{{ $money($item->price) }}</p>
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ implode(' · ', array_filter([
                                        $item->size > 0 ? number_format($item->size, 0, ',', '.') . ' m²' : null,
                                        $item->rooms ? $item->rooms . ' dorm.' : null,
                                        $item->bath ? $item->bath . ' baños' : null,
                                        $item->city,
                                    ])) }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
            @endif

            @if(count($landingLinks) > 0)
            <section class="bg-white rounded-lg shadow p-5 sm:p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-3">Búsquedas relacionadas</h2>
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                    @foreach($landingLinks as $link)
                        <li><a href="{{ $link['url'] }}" class="text-sm text-[#008bea] hover:underline">{{ $link['title'] }}</a></li>
                    @endforeach
                </ul>
            </section>
            @endif
        </div>

        <aside class="hidden lg:block">
            <div class="sticky top-6">
                <div class="w-[300px] h-[600px] rounded-lg border border-gray-200 shadow overflow-hidden bg-white flex items-center justify-center">
                    <ins
                        class="adsbygoogle js-adsense"
                        style="display:inline-block;width:300px;height:600px"
                        data-ad-client="ca-pub-4474623749606512"
                        data-ad-slot="4621188394"
                    ></ins>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
    (() => {
        const properties = {
            vertical: 'casas',
            country: @json($country),
            ad_id: @json($adId),
            source: @json($source),
        };
        (window.mixpanelEvents = window.mixpanelEvents || []).push(['Ad Detail Viewed', properties]);

        document.querySelectorAll('.js-outbound').forEach((link) => {
            link.addEventListener('click', () => {
                window.trackEvent?.('Ad Outbound Click', properties);
            });
        });
    })();
</script>
@endsection
