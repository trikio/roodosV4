@extends('layouts.marketplace')

@php
    $currencySymbol = config('countries.currency.' . strtoupper($country), '$');
    $money = fn (int $value) => $currencySymbol . ' ' . number_format($value, 0, ',', '.');
    $makeModel = trim($car->brand . ' ' . $car->model);
    $place = implode(', ', array_filter([$car->city, $car->location]));

    $specs = array_filter([
        'Año' => $car->year,
        'Kilometraje' => $car->kilometers ? number_format($car->kilometers, 0, ',', '.') . ' km' : null,
        'Marca' => $car->brand,
        'Modelo' => $car->model,
        'Ubicación' => $place,
        'Transmisión' => $car->transmission,
        'Combustible' => $car->fuel,
        'Carrocería' => $car->body,
    ], fn ($value) => filled($value));

    $breadcrumbItems = array_values(array_filter([
        ['label' => 'Inicio', 'url' => url('/')],
        $makeUrl ? ['label' => $car->brand, 'url' => $makeUrl] : null,
        $modelUrl ? ['label' => $makeModel, 'url' => $modelUrl] : null,
        ['label' => $car->title, 'url' => null],
    ]));

    // Where this ad's price falls among the same model of similar years.
    $priceVerdict = null;
    if ($priceStats && $car->price > 0) {
        $diff = ($car->price - $priceStats['avg']) / max(1, $priceStats['avg']);
        $priceVerdict = match (true) {
            $diff <= -0.1 => ['Bajo el promedio', 'text-green-700 bg-green-50'],
            $diff >= 0.1 => ['Sobre el promedio', 'text-amber-700 bg-amber-50'],
            default => ['En el promedio', 'text-gray-700 bg-gray-100'],
        };
    }
    $detailSlot = config('services.adsense.detail_slot');
@endphp

@section('page_title', $car->title)
@section('html_title', $car->title . ($car->price > 0 ? ' - ' . $money($car->price) : '') . ' - Roodos Autos')
@section('meta_description', trim($car->title . ' ' . implode(' · ', array_slice($specs, 0, 3))))
@section('robots_content', 'noindex,nofollow')

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
                        @if($car->image_url)
                            <img src="{{ $car->image_url }}" alt="{{ $car->title }}" class="w-full h-full object-cover" fetchpriority="high">
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <img src="{{ asset('assets/img/cars_logo_v2.png') }}" alt="" class="w-16 h-16 object-contain grayscale opacity-35">
                            </div>
                        @endif
                    </div>

                    <div class="flex-1 min-w-0">
                        <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ $car->title }}</h1>
                        @if($car->price > 0)
                            <p class="text-3xl font-bold text-gray-900 mb-2">{{ $money($car->price) }}</p>
                        @endif
                        @if($priceVerdict)
                            <span class="inline-block text-xs font-medium px-2 py-1 rounded {{ $priceVerdict[1] }} mb-3">{{ $priceVerdict[0] }}</span>
                        @endif
                        <p class="text-sm text-gray-600">
                            {{ implode(' · ', array_filter([$car->year, $car->kilometers ? number_format($car->kilometers, 0, ',', '.') . ' km' : null, $place])) }}
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
            </section>
            @endif

            {{-- Precio comparado --}}
            @if($priceStats)
            <section class="bg-white rounded-lg shadow p-5 sm:p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-1">Precio de {{ $makeModel }} {{ $yearSpan[0] }}–{{ $yearSpan[1] }}</h2>
                <p class="text-sm text-gray-500 mb-4">Según {{ $priceStats['total'] }} avisos publicados en Roodos.</p>
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-xs text-gray-500">Mínimo</p>
                        <p class="font-semibold text-gray-900">{{ $money($priceStats['min']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Promedio</p>
                        <p class="font-semibold text-gray-900">{{ $money($priceStats['avg']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Máximo</p>
                        <p class="font-semibold text-gray-900">{{ $money($priceStats['max']) }}</p>
                    </div>
                </div>
                @if($modelUrl)
                    <a href="{{ $modelUrl }}" class="inline-block mt-4 text-sm text-[#008bea] hover:underline">Ver todos los {{ $makeModel }}</a>
                @endif
            </section>
            @endif

            @if($detailSlot)
            <div class="min-h-[100px]">
                <ins
                    class="adsbygoogle js-lazy-adsense"
                    style="display:block"
                    data-ad-client="ca-pub-4474623749606512"
                    data-ad-slot="{{ $detailSlot }}"
                    data-ad-format="auto"
                    data-full-width-responsive="true"
                ></ins>
            </div>
            @endif

            {{-- Similares --}}
            @if(count($similar) > 0)
            <section>
                <h2 class="text-lg font-bold text-gray-900 mb-4">{{ $makeModel !== '' ? $makeModel . ' similares' : 'Autos similares' }}</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach($similar as $item)
                        <a href="/auto/{{ $item->id }}" rel="nofollow" class="flex gap-3 bg-white rounded-lg shadow hover:shadow-lg transition p-3">
                            <div class="flex-shrink-0 w-24 h-24 bg-gray-100 overflow-hidden rounded">
                                @if($item->image_url)
                                    <img src="{{ $item->image_url }}" alt="{{ $item->title }}" class="w-full h-full object-cover" loading="lazy" decoding="async">
                                @endif
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 line-clamp-2">{{ $item->title }}</p>
                                <p class="font-bold text-gray-900 mt-1">{{ $money($item->price) }}</p>
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ implode(' · ', array_filter([$item->year, $item->kilometers ? number_format($item->kilometers, 0, ',', '.') . ' km' : null, $item->nexo_id])) }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
            @endif

            @if($makeUrl || $modelUrl)
            <div class="flex flex-wrap gap-3 text-sm">
                @if($modelUrl)
                    <a href="{{ $modelUrl }}" class="px-4 py-2 bg-white rounded-lg shadow text-gray-700 hover:text-[#008bea]">Más {{ $makeModel }} usados</a>
                @endif
                @if($makeUrl)
                    <a href="{{ $makeUrl }}" class="px-4 py-2 bg-white rounded-lg shadow text-gray-700 hover:text-[#008bea]">Todos los {{ $car->brand }}</a>
                @endif
            </div>
            @endif
        </div>

        <aside class="hidden lg:block">
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
        </aside>
    </div>
</div>

<script>
    (() => {
        const properties = {
            vertical: 'autos',
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
