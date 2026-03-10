@php
    $visibleFilterLimit = 12;
    $searchUrl = route('cars.index', ['country' => request()->route('country') ?: ($country ?? 'lab')]);
@endphp

<!-- Brand Filter -->
@if($brands->isNotEmpty())
<div class="pb-3 border-b border-gray-200" data-filter-group>
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Marca</h3>
    <ul class="space-y-0.5">
        @foreach($brands as $brand)
        @php
            $isActive = request('brand') == $brand['id'];
            $baseParams = array_filter(request()->except(['brand']), static fn ($value) => $value !== null && $value !== '');
            $inactiveParams = array_merge($baseParams, ['brand' => $brand['id']]);
            $uri = $isActive
                ? (empty($baseParams) ? $searchUrl : $searchUrl . '?' . http_build_query($baseParams))
                : $searchUrl . '?' . http_build_query($inactiveParams);
        @endphp
        <li class="{{ $loop->index >= $visibleFilterLimit && !$isActive ? 'hidden filter-extra-option' : '' }}">
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
               data-uri="{{ $uri }}">
                <span>{{ $brand['name'] }}</span>
                @if($isActive)
                    <span class="text-white font-bold text-base">×</span>
                @else
                    <span class="text-xs text-gray-500">{{ $brand['total'] }}</span>
                @endif
            </p>
        </li>
        @endforeach
    </ul>
    @if($brands->count() > $visibleFilterLimit)
        <button
            type="button"
            class="toggle-filter-options mt-2 text-xs text-[#008bea] hover:underline"
            data-open-text="Ver todos ({{ $brands->count() - $visibleFilterLimit }})"
            data-close-text="Ver menos">
            Ver todos ({{ $brands->count() - $visibleFilterLimit }})
        </button>
    @endif
</div>
@endif

<!-- Model Filter -->
@if($models->isNotEmpty())
<div class="pb-3 border-b border-gray-200" data-filter-group>
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Modelo</h3>
    <ul class="space-y-0.5">
        @foreach($models as $model)
        @php
            $isActive = request('model') == $model['id'];
            $baseParams = array_filter(request()->except(['model']), static fn ($value) => $value !== null && $value !== '');
            $inactiveParams = array_merge($baseParams, ['model' => $model['id']]);
            $uri = $isActive
                ? (empty($baseParams) ? $searchUrl : $searchUrl . '?' . http_build_query($baseParams))
                : $searchUrl . '?' . http_build_query($inactiveParams);
        @endphp
        <li class="{{ $loop->index >= $visibleFilterLimit && !$isActive ? 'hidden filter-extra-option' : '' }}">
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
               data-uri="{{ $uri }}">
                <span>{{ $model['name'] }}</span>
                @if($isActive)
                    <span class="text-white font-bold text-base">×</span>
                @else
                    <span class="text-xs text-gray-500">{{ $model['total'] }}</span>
                @endif
            </p>
        </li>
        @endforeach
    </ul>
    @if($models->count() > $visibleFilterLimit)
        <button
            type="button"
            class="toggle-filter-options mt-2 text-xs text-[#008bea] hover:underline"
            data-open-text="Ver todos ({{ $models->count() - $visibleFilterLimit }})"
            data-close-text="Ver menos">
            Ver todos ({{ $models->count() - $visibleFilterLimit }})
        </button>
    @endif
</div>
@endif

<!-- Price Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Precio</h3>
    <div class="space-y-2">
        <div>
            <label class="text-xs text-gray-600">Mínimo</label>
            <select name="min_price" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="1000000" {{ request('min_price') == '1000000' ? 'selected' : '' }}>$1.000.000</option>
                <option value="5000000" {{ request('min_price') == '5000000' ? 'selected' : '' }}>$5.000.000</option>
                <option value="10000000" {{ request('min_price') == '10000000' ? 'selected' : '' }}>$10.000.000</option>
                <option value="20000000" {{ request('min_price') == '20000000' ? 'selected' : '' }}>$20.000.000</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="max_price" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="5000000" {{ request('max_price') == '5000000' ? 'selected' : '' }}>$5.000.000</option>
                <option value="10000000" {{ request('max_price') == '10000000' ? 'selected' : '' }}>$10.000.000</option>
                <option value="20000000" {{ request('max_price') == '20000000' ? 'selected' : '' }}>$20.000.000</option>
                <option value="50000000" {{ request('max_price') == '50000000' ? 'selected' : '' }}>$50.000.000</option>
            </select>
        </div>
    </div>
</div>

<!-- Year Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Año</h3>
    <div class="space-y-2">
        <div>
            <label class="text-xs text-gray-600">Mínimo</label>
            <select name="min_year" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                @for($year = 1990; $year <= date('Y'); $year++)
                    <option value="{{ $year }}" {{ request('min_year') == $year ? 'selected' : '' }}>{{ $year }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="max_year" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                @for($year = 1990; $year <= date('Y'); $year++)
                    <option value="{{ $year }}" {{ request('max_year') == $year ? 'selected' : '' }}>{{ $year }}</option>
                @endfor
            </select>
        </div>
    </div>
</div>

<!-- Km Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Kilómetros</h3>
    <div class="space-y-2">
        <div>
            <label class="text-xs text-gray-600">Mínimo</label>
            <select name="min_km" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="0" {{ request('min_km') == '0' ? 'selected' : '' }}>0</option>
                <option value="50000" {{ request('min_km') == '50000' ? 'selected' : '' }}>50.000</option>
                <option value="100000" {{ request('min_km') == '100000' ? 'selected' : '' }}>100.000</option>
                <option value="200000" {{ request('min_km') == '200000' ? 'selected' : '' }}>200.000</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="max_km" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="100000" {{ request('max_km') == '100000' ? 'selected' : '' }}>100.000</option>
                <option value="200000" {{ request('max_km') == '200000' ? 'selected' : '' }}>200.000</option>
                <option value="500000" {{ request('max_km') == '500000' ? 'selected' : '' }}>500.000</option>
            </select>
        </div>
    </div>
</div>

<!-- Location Filter -->
@if($locations->isNotEmpty())
<div class="pb-3 border-b border-gray-200" data-filter-group>
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Región</h3>
    <ul class="space-y-0.5">
        @foreach($locations as $location)
        @php
            $isActive = request('location') == $location['id'];
            $baseParams = array_filter(request()->except(['location']), static fn ($value) => $value !== null && $value !== '');
            $inactiveParams = array_merge($baseParams, ['location' => $location['id']]);
            $uri = $isActive
                ? (empty($baseParams) ? $searchUrl : $searchUrl . '?' . http_build_query($baseParams))
                : $searchUrl . '?' . http_build_query($inactiveParams);
        @endphp
        <li class="{{ $loop->index >= $visibleFilterLimit && !$isActive ? 'hidden filter-extra-option' : '' }}">
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
               data-uri="{{ $uri }}">
                <span>{{ $location['name'] }}</span>
                @if($isActive)
                    <span class="text-white font-bold text-base">×</span>
                @else
                    <span class="text-xs text-gray-500">{{ $location['total'] }}</span>
                @endif
            </p>
        </li>
        @endforeach
    </ul>
    @if($locations->count() > $visibleFilterLimit)
        <button
            type="button"
            class="toggle-filter-options mt-2 text-xs text-[#008bea] hover:underline"
            data-open-text="Ver todos ({{ $locations->count() - $visibleFilterLimit }})"
            data-close-text="Ver menos">
            Ver todos ({{ $locations->count() - $visibleFilterLimit }})
        </button>
    @endif
</div>
@endif

<!-- City Filter -->
@if($cities->isNotEmpty())
<div class="pb-3 border-b border-gray-200" data-filter-group>
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Ciudad</h3>
    <ul class="space-y-0.5">
        @foreach($cities as $city)
        @php
            $isActive = request('city') == $city['id'];
            $baseParams = array_filter(request()->except(['city']), static fn ($value) => $value !== null && $value !== '');
            $inactiveParams = array_merge($baseParams, ['city' => $city['id']]);
            $uri = $isActive
                ? (empty($baseParams) ? $searchUrl : $searchUrl . '?' . http_build_query($baseParams))
                : $searchUrl . '?' . http_build_query($inactiveParams);
        @endphp
        <li class="{{ $loop->index >= $visibleFilterLimit && !$isActive ? 'hidden filter-extra-option' : '' }}">
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
               data-uri="{{ $uri }}">
                <span>{{ $city['name'] }}</span>
                @if($isActive)
                    <span class="text-white font-bold text-base">×</span>
                @else
                    <span class="text-xs text-gray-500">{{ $city['total'] }}</span>
                @endif
            </p>
        </li>
        @endforeach
    </ul>
    @if($cities->count() > $visibleFilterLimit)
        <button
            type="button"
            class="toggle-filter-options mt-2 text-xs text-[#008bea] hover:underline"
            data-open-text="Ver todos ({{ $cities->count() - $visibleFilterLimit }})"
            data-close-text="Ver menos">
            Ver todos ({{ $cities->count() - $visibleFilterLimit }})
        </button>
    @endif
</div>
@endif
