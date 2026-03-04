@extends('layouts.marketplace')

@section('page_title', 'Nuestras Redes')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="bg-white rounded-xl shadow p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">@yield('page_title')</h1>
        <ul class="space-y-3 text-gray-700">
            <li><a href="https://roodos.cl" target="_blank" rel="noopener noreferrer" class="text-[#008bea] hover:underline">Roodos Chile</a></li>
            <li><a href="https://roodos.pe" target="_blank" rel="noopener noreferrer" class="text-[#008bea] hover:underline">Roodos Perú</a></li>
            <li><a href="https://roodos.ec" target="_blank" rel="noopener noreferrer" class="text-[#008bea] hover:underline">Roodos Ecuador</a></li>
            <li><a href="https://roodos.mx" target="_blank" rel="noopener noreferrer" class="text-[#008bea] hover:underline">Roodos México</a></li>
        </ul>
    </div>
</div>
@endsection
