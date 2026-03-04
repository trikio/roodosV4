@extends('layouts.marketplace')

@section('page_title', 'Política de privacidad')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="bg-white rounded-xl shadow p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">@yield('page_title')</h1>
        <p class="text-gray-700 mb-4">
            En Roodos respetamos su privacidad y protegemos sus datos personales.
        </p>

        <h2 class="text-xl font-semibold text-gray-900 mb-3 mt-8">Información recopilada</h2>
        <ul class="list-disc pl-6 text-gray-700 space-y-2 mb-4">
            <li>Dirección IP</li>
            <li>Datos de navegación</li>
            <li>Información enviada a través de formularios</li>
            <li>Cookies y tecnologías similares</li>
        </ul>

        <h2 class="text-xl font-semibold text-gray-900 mb-3 mt-8">Finalidad del uso</h2>
        <p class="text-gray-700 mb-4">
            Utilizamos la información para mejorar la experiencia de búsqueda, analizar el comportamiento de navegación y responder consultas.
        </p>
        <p class="text-gray-700">
            No vendemos datos personales a terceros.
        </p>
    </div>
</div>
@endsection
