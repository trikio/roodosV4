@php($money = fn (int $value) => $currency . ' ' . number_format($value, 0, ',', '.'))
<figure>
    @if(count($rows) > 0)
        <table>
            <thead>
                <tr><th>Año</th><th>Precio promedio</th><th>Rango</th><th>Avisos</th></tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['year'] }}</td>
                        <td>{{ $money($row['avg']) }}</td>
                        <td class="whitespace-nowrap">{{ $money($row['min']) }} – {{ $money($row['max']) }}</td>
                        <td>{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <figcaption class="text-sm text-gray-500 mt-2">
            Precios publicados de {{ $name }} usados en Roodos, actualizados a diario. Solo años con 3 avisos o más.
            <a href="{{ $modelUrl }}">Ver los {{ $name }} disponibles</a>
        </figcaption>
    @else
        <p class="text-gray-600">
            Hoy no hay suficientes avisos de {{ $name }} para armar una tabla de precios por año.
            <a href="{{ $modelUrl }}">Ver los {{ $name }} disponibles</a>
        </p>
    @endif
</figure>
