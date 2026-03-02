<!-- Brand Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Marca</h3>
    <ul class="space-y-0.5">
        @foreach($brands as $brand)
        <li>
            <p class="filter-link flex items-center justify-between text-[#008bea] hover:underline text-sm cursor-pointer {{ request('brand') == $brand ? 'font-semibold' : '' }}"
               data-uri="{{ url()->current() }}?{{ http_build_query(array_merge(request()->except('brand'), ['brand' => $brand])) }}">
                <span>{{ $brand }}</span>
                <span class="badge-light text-xs text-gray-500">{{ $brandCounts[$brand] ?? 0 }}</span>
            </p>
        </li>
        @endforeach
    </ul>
</div>

<!-- Model Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Modelo</h3>
    <ul class="space-y-0.5">
        @foreach($models as $model)
        <li>
            <p class="filter-link flex items-center justify-between text-[#008bea] hover:underline text-sm cursor-pointer {{ request('model') == $model ? 'font-semibold' : '' }}"
               data-uri="{{ url()->current() }}?{{ http_build_query(array_merge(request()->except('model'), ['model' => $model])) }}">
                <span>{{ $model }}</span>
                <span class="badge-light text-xs text-gray-500">{{ $modelCounts[$model] ?? 0 }}</span>
            </p>
        </li>
        @endforeach
    </ul>
</div>

<!-- Price Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Precio</h3>
    <div class="space-y-2">
        <div>
            <label class="text-xs text-gray-600">Mínimo</label>
            <select name="price_min" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="1000000" {{ request('price_min') == '1000000' ? 'selected' : '' }}>$1.000.000</option>
                <option value="5000000" {{ request('price_min') == '5000000' ? 'selected' : '' }}>$5.000.000</option>
                <option value="10000000" {{ request('price_min') == '10000000' ? 'selected' : '' }}>$10.000.000</option>
                <option value="20000000" {{ request('price_min') == '20000000' ? 'selected' : '' }}>$20.000.000</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="price_max" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="5000000" {{ request('price_max') == '5000000' ? 'selected' : '' }}>$5.000.000</option>
                <option value="10000000" {{ request('price_max') == '10000000' ? 'selected' : '' }}>$10.000.000</option>
                <option value="20000000" {{ request('price_max') == '20000000' ? 'selected' : '' }}>$20.000.000</option>
                <option value="50000000" {{ request('price_max') == '50000000' ? 'selected' : '' }}>$50.000.000</option>
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
            <select name="year_min" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="2015" {{ request('year_min') == '2015' ? 'selected' : '' }}>2015</option>
                <option value="2018" {{ request('year_min') == '2018' ? 'selected' : '' }}>2018</option>
                <option value="2020" {{ request('year_min') == '2020' ? 'selected' : '' }}>2020</option>
                <option value="2023" {{ request('year_min') == '2023' ? 'selected' : '' }}>2023</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="year_max" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="2020" {{ request('year_max') == '2020' ? 'selected' : '' }}>2020</option>
                <option value="2023" {{ request('year_max') == '2023' ? 'selected' : '' }}>2023</option>
                <option value="2025" {{ request('year_max') == '2025' ? 'selected' : '' }}>2025</option>
                <option value="2026" {{ request('year_max') == '2026' ? 'selected' : '' }}>2026</option>
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
            <select name="km_min" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="0" {{ request('km_min') == '0' ? 'selected' : '' }}>0</option>
                <option value="50000" {{ request('km_min') == '50000' ? 'selected' : '' }}>50.000</option>
                <option value="100000" {{ request('km_min') == '100000' ? 'selected' : '' }}>100.000</option>
                <option value="200000" {{ request('km_min') == '200000' ? 'selected' : '' }}>200.000</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-600">Máximo</label>
            <select name="km_max" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm">
                <option value="">Cualquiera</option>
                <option value="100000" {{ request('km_max') == '100000' ? 'selected' : '' }}>100.000</option>
                <option value="200000" {{ request('km_max') == '200000' ? 'selected' : '' }}>200.000</option>
                <option value="500000" {{ request('km_max') == '500000' ? 'selected' : '' }}>500.000</option>
            </select>
        </div>
    </div>
</div>

<!-- Location Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Región</h3>
    <ul class="space-y-0.5">
        @foreach($locations as $location)
        <li>
            <p class="filter-link flex items-center justify-between text-[#008bea] hover:underline text-sm cursor-pointer {{ request('location') == $location ? 'font-semibold' : '' }}"
               data-uri="{{ url()->current() }}?{{ http_build_query(array_merge(request()->except('location'), ['location' => $location])) }}">
                <span>{{ $location }}</span>
                <span class="badge-light text-xs text-gray-500">{{ $locationCounts[$location] ?? 0 }}</span>
            </p>
        </li>
        @endforeach
    </ul>
</div>

<!-- City Filter -->
<div class="pb-3 border-b border-gray-200">
    <h3 class="font-bold text-gray-900 mb-2 text-sm">Ciudad</h3>
    <ul class="space-y-0.5">
        @foreach($cities as $city)
        <li>
            <p class="filter-link flex items-center justify-between text-[#008bea] hover:underline text-sm cursor-pointer {{ request('city') == $city ? 'font-semibold' : '' }}"
               data-uri="{{ url()->current() }}?{{ http_build_query(array_merge(request()->except('city'), ['city' => $city])) }}">
                <span>{{ $city }}</span>
                <span class="badge-light text-xs text-gray-500">{{ $cityCounts[$city] ?? 0 }}</span>
            </p>
        </li>
        @endforeach
    </ul>
</div>