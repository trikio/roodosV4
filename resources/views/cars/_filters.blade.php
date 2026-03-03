<!-- Brand Filter -->
@if($brands->isNotEmpty())
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Marca</h3>
    <ul class="space-y-0.5">
        @foreach($brands as $brand)
        @php
            $isActive = request('brand') == $brand['id'];
            // Don't include 'q' in landing pages (slug already contains search info)
            $baseParams = request()->except(['brand', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['brand' => $brand['id']]));
        @endphp
        <li>
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 py-1 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
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
</div>
@endif

<!-- Model Filter -->
@if($models->isNotEmpty())
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Modelo</h3>
    <ul class="space-y-0.5">
        @foreach($models as $model)
        @php
            $isActive = request('model') == $model['id'];
            // Don't include 'q' in landing pages (slug already contains search info)
            $baseParams = request()->except(['model', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['model' => $model['id']]));
        @endphp
        <li>
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 py-1 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
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
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Región</h3>
    <ul class="space-y-0.5">
        @foreach($locations as $location)
        @php
            $isActive = request('location') == $location['id'];
            // Don't include 'q' in landing pages (slug already contains search info)
            $baseParams = request()->except(['location', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['location' => $location['id']]));
        @endphp
        <li>
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 py-1 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
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
</div>
@endif

<!-- City Filter -->
@if($cities->isNotEmpty())
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Ciudad</h3>
    <ul class="space-y-0.5">
        @foreach($cities as $city)
        @php
            $isActive = request('city') == $city['id'];
            // Don't include 'q' in landing pages (slug already contains search info)
            $baseParams = request()->except(['city', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['city' => $city['id']]));
        @endphp
        <li>
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 py-1 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
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
</div>
@endif