@php
    $visibleFilterLimit = 12;
@endphp

<!-- Operation Filter -->
@if($operations->isNotEmpty())
<div class="pb-3 border-b border-gray-200" data-filter-group>
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Operación</h3>
    <ul class="space-y-0.5">
        @foreach($operations as $operation)
        @php
            $isActive = request('operation') == $operation['id'];
            $baseParams = request()->except(['operation', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['operation' => $operation['id']]));
        @endphp
        <li class="{{ $loop->index >= $visibleFilterLimit && !$isActive ? 'hidden filter-extra-option' : '' }}">
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
               data-uri="{{ $uri }}">
                <span>{{ $operation['name'] }}</span>
                @if($isActive)
                    <span class="text-white font-bold text-base">×</span>
                @else
                    <span class="text-xs text-gray-500">{{ $operation['total'] }}</span>
                @endif
            </p>
        </li>
        @endforeach
    </ul>
    @if($operations->count() > $visibleFilterLimit)
        <button
            type="button"
            class="toggle-filter-options mt-2 text-xs text-[#008bea] hover:underline"
            data-open-text="Ver todos ({{ $operations->count() - $visibleFilterLimit }})"
            data-close-text="Ver menos">
            Ver todos ({{ $operations->count() - $visibleFilterLimit }})
        </button>
    @endif
</div>
@endif

<!-- Type Filter -->
@if($types->isNotEmpty())
<div class="pb-3 border-b border-gray-200" data-filter-group>
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Tipo</h3>
    <ul class="space-y-0.5">
        @foreach($types as $typeOption)
        @php
            $isActive = request('type') == $typeOption['id'];
            $baseParams = request()->except(['type', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['type' => $typeOption['id']]));
        @endphp
        <li class="{{ $loop->index >= $visibleFilterLimit && !$isActive ? 'hidden filter-extra-option' : '' }}">
            <p class="filter-link flex items-center justify-between text-sm cursor-pointer rounded px-2 {{ $isActive ? 'bg-[#008bea] text-white font-semibold' : 'text-[#008bea] hover:bg-gray-100' }}"
               data-uri="{{ $uri }}">
                <span>{{ $typeOption['name'] }}</span>
                @if($isActive)
                    <span class="text-white font-bold text-base">×</span>
                @else
                    <span class="text-xs text-gray-500">{{ $typeOption['total'] }}</span>
                @endif
            </p>
        </li>
        @endforeach
    </ul>
    @if($types->count() > $visibleFilterLimit)
        <button
            type="button"
            class="toggle-filter-options mt-2 text-xs text-[#008bea] hover:underline"
            data-open-text="Ver todos ({{ $types->count() - $visibleFilterLimit }})"
            data-close-text="Ver menos">
            Ver todos ({{ $types->count() - $visibleFilterLimit }})
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
                <option value="50000" {{ request('min_price') == '50000' ? 'selected' : '' }}>$50.000</option>
                <option value="100000" {{ request('min_price') == '100000' ? 'selected' : '' }}>$100.000</option>
                <option value="200000" {{ request('min_price') == '200000' ? 'selected' : '' }}>$200.000</option>
                <option value="500000" {{ request('min_price') == '500000' ? 'selected' : '' }}>$500.000</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="max_price" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="100000" {{ request('max_price') == '100000' ? 'selected' : '' }}>$100.000</option>
                <option value="200000" {{ request('max_price') == '200000' ? 'selected' : '' }}>$200.000</option>
                <option value="500000" {{ request('max_price') == '500000' ? 'selected' : '' }}>$500.000</option>
                <option value="1000000" {{ request('max_price') == '1000000' ? 'selected' : '' }}>$1.000.000</option>
            </select>
        </div>
    </div>
</div>

<!-- Rooms Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Habitaciones</h3>
    <select name="rooms" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
        <option value="">Cualquiera</option>
        <option value="1" {{ request('rooms') == '1' ? 'selected' : '' }}>1+</option>
        <option value="2" {{ request('rooms') == '2' ? 'selected' : '' }}>2+</option>
        <option value="3" {{ request('rooms') == '3' ? 'selected' : '' }}>3+</option>
        <option value="4" {{ request('rooms') == '4' ? 'selected' : '' }}>4+</option>
        <option value="5" {{ request('rooms') == '5' ? 'selected' : '' }}>5+</option>
    </select>
</div>

<!-- Bath Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Baños</h3>
    <select name="bath" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
        <option value="">Cualquiera</option>
        <option value="1" {{ request('bath') == '1' ? 'selected' : '' }}>1+</option>
        <option value="2" {{ request('bath') == '2' ? 'selected' : '' }}>2+</option>
        <option value="3" {{ request('bath') == '3' ? 'selected' : '' }}>3+</option>
        <option value="4" {{ request('bath') == '4' ? 'selected' : '' }}>4+</option>
    </select>
</div>

<!-- Size Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Superficie (m²)</h3>
    <div class="space-y-2">
        <div>
            <label class="text-xs text-gray-600">Mínimo</label>
            <select name="min_size" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="30" {{ request('min_size') == '30' ? 'selected' : '' }}>30</option>
                <option value="50" {{ request('min_size') == '50' ? 'selected' : '' }}>50</option>
                <option value="80" {{ request('min_size') == '80' ? 'selected' : '' }}>80</option>
                <option value="120" {{ request('min_size') == '120' ? 'selected' : '' }}>120</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="max_size" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="80" {{ request('max_size') == '80' ? 'selected' : '' }}>80</option>
                <option value="120" {{ request('max_size') == '120' ? 'selected' : '' }}>120</option>
                <option value="200" {{ request('max_size') == '200' ? 'selected' : '' }}>200</option>
                <option value="500" {{ request('max_size') == '500' ? 'selected' : '' }}>500</option>
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
            $baseParams = request()->except(['location', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['location' => $location['id']]));
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
            $baseParams = request()->except(['city', 'q']);
            $uri = $isActive
                ? request()->path() . '?' . http_build_query(array_filter($baseParams))
                : request()->path() . '?' . http_build_query(array_merge($baseParams, ['city' => $city['id']]));
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
