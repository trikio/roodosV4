@extends('layouts.marketplace')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="bg-white rounded-xl shadow p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">Envíe su sitio</h1>
        <p class="text-gray-700 mb-4">
            Si administra un portal de venta de vehículos, concesionaria o automotora, puede integrar su inventario en Roodos y aumentar su visibilidad frente a compradores activos.
        </p>
        <p class="text-gray-700 mb-4">
            Roodos funciona como un metabuscador que dirige tráfico calificado hacia los sitios originales de publicación.
        </p>

        <h2 class="text-xl font-semibold text-gray-900 mb-3 mt-8">¿Qué formatos aceptamos?</h2>
        <p class="text-gray-700 mb-4">Aceptamos distintas modalidades de integración:</p>
        <ul class="list-disc pl-6 text-gray-700 space-y-2 mb-4">
            <li>Feed XML</li>
            <li>Integraciones compatibles con Trovit</li>
            <li>Integraciones compatibles con Mitula</li>
            <li>Feed JSON</li>
            <li>API propia</li>
            <li>Sincronización automatizada</li>
        </ul>
        <p class="text-gray-700 mb-4">
            Si ya trabaja con Trovit o Mitula, es muy probable que podamos integrar su inventario rápidamente.
        </p>

        <h2 class="text-xl font-semibold text-gray-900 mb-3 mt-8">Beneficios de publicar en Roodos</h2>
        <ul class="list-disc pl-6 text-gray-700 space-y-2">
            <li>Mayor exposición en búsquedas orgánicas</li>
            <li>Tráfico altamente segmentado</li>
            <li>Usuarios con intención real de compra</li>
            <li>Incremento de leads hacia su portal</li>
            <li>Sin interferir en su proceso comercial</li>
        </ul>
    </div>
</div>
@endsection
