{{-- Precio según kilometraje (App\Support\CarPriceChart). Espera $priceChart, $chartTitle y $money. --}}
@php
    $chartColors = [1 => '#257de0', 2 => '#f47230', 3 => '#00b894'];
@endphp
<section class="bg-white rounded-lg shadow p-5 sm:p-6">
    <h2 class="text-lg font-bold text-gray-900 mb-1">{{ $chartTitle }}</h2>
    <p class="text-sm text-gray-500 mb-4">
        Cada punto es un aviso publicado en Roodos ({{ number_format($priceChart['total'], 0, ',', '.') }}); la línea es el precio típico según los kilómetros.
    </p>

    <svg viewBox="0 0 {{ $priceChart['width'] }} {{ $priceChart['height'] }}" class="w-full max-w-[620px] h-auto" role="img"
         aria-label="{{ $chartTitle }}: precio de cada aviso según su kilometraje, por año">
        <style>
            .pc-dot { fill-opacity: .6; stroke: #fff; stroke-width: 1; }
            .pc-dot:hover { fill-opacity: 1; stroke: #111827; stroke-width: 1.5; }
            .pc-tick { font-size: 14px; fill: #6b7280; }
        </style>
        @php $plot = $priceChart['plot']; @endphp
        @foreach($priceChart['y_ticks'] as $tick)
            <line x1="{{ $plot['left'] }}" x2="{{ $plot['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="#e5e7eb" stroke-width="1"/>
            <text x="{{ $plot['left'] - 8 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end" class="pc-tick">{{ $tick['label'] }}</text>
        @endforeach
        @foreach($priceChart['x_ticks'] as $tick)
            <text x="{{ $tick['x'] }}" y="{{ $plot['bottom'] + 20 }}" text-anchor="{{ $loop->first ? 'start' : ($loop->last ? 'end' : 'middle') }}" class="pc-tick">{{ $tick['label'] }}{{ $loop->last ? ' km' : '' }}</text>
        @endforeach

        @foreach($priceChart['groups'] as $group)
            <g fill="{{ $chartColors[$group['slot']] }}">
                @foreach($group['dots'] as $dot)
                    <circle class="pc-dot" cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="4"><title>{{ $dot['title'] }}</title></circle>
                @endforeach
            </g>
        @endforeach
        @foreach($priceChart['groups'] as $group)
            @if($group['trend'])
                <path d="{{ $group['trend'] }}" fill="none" stroke="#fff" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"/>
                <path d="{{ $group['trend'] }}" fill="none" stroke="{{ $chartColors[$group['slot']] }}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
            @endif
        @endforeach

        @if($priceChart['highlight'])
            <circle cx="{{ $priceChart['highlight']['x'] }}" cy="{{ $priceChart['highlight']['y'] }}" r="7" fill="#111827" stroke="#fff" stroke-width="2.5">
                <title>Este auto: {{ $priceChart['highlight']['title'] }}</title>
            </circle>
        @endif
    </svg>

    <ul class="flex flex-wrap gap-x-5 gap-y-2 mt-3 text-sm text-gray-700">
        @foreach($priceChart['groups'] as $group)
            <li class="flex items-center gap-2">
                <span class="inline-block w-3 h-3 rounded-full" style="background: {{ $chartColors[$group['slot']] }}"></span>
                {{ $group['label'] }}
            </li>
        @endforeach
        @if($priceChart['highlight'])
            <li class="flex items-center gap-2">
                <span class="inline-block w-3.5 h-3.5 rounded-full bg-gray-900 ring-2 ring-white outline outline-1 outline-gray-300"></span>
                Este auto
            </li>
        @endif
    </ul>

    <table class="w-full mt-4 text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b border-gray-200">
                <th class="py-2 font-medium">Años</th>
                <th class="py-2 font-medium text-right">Avisos</th>
                <th class="py-2 font-medium text-right">Precio típico</th>
            </tr>
        </thead>
        <tbody>
            @foreach(array_reverse($priceChart['groups']) as $group)
                <tr class="border-b border-gray-100 last:border-0">
                    <td class="py-2 text-gray-900">{{ $group['label'] }}</td>
                    <td class="py-2 text-right text-gray-700">{{ number_format($group['count'], 0, ',', '.') }}</td>
                    <td class="py-2 text-right font-semibold text-gray-900">{{ $money($group['median']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
