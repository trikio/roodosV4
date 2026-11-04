@extends('layouts.marketplace')

@section('page_title', 'Redirigiendo al aviso...')
@section('robots_content', 'noindex,nofollow')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <h1 class="text-2xl font-bold text-gray-900 mb-3">@yield('page_title')</h1>
        <p class="text-gray-600 mb-6">Serás enviado en 0.5 segundos.</p>
        <p class="text-sm text-gray-500 mb-6">Te estamos llevando a la fuente: {{ $source }}</p>
        <a href="{{ $targetUrl }}" rel="nofollow noopener noreferrer" class="inline-block bg-[#008bea] hover:bg-[#007acc] text-white px-5 py-2 rounded-lg font-medium">
            Ir ahora
        </a>
    </div>
</div>

<script>
    (() => {
        const startedAt = Date.now();
        let redirected = false;
        const redirect = () => {
            if (redirected) return;
            redirected = true;
            window.location.href = @json($targetUrl);
        };

        // Redirige a los 0.5 s o cuando Mixpanel envíe el evento, sin pasar de 1.5 s.
        (window.mixpanelEvents = window.mixpanelEvents || []).push(['Ad Detail Viewed', {
            vertical: 'casas',
            country: @json($country),
            ad_id: @json($adId),
            source: @json($source),
        }, () => setTimeout(redirect, Math.max(0, 500 - (Date.now() - startedAt)))]);
        setTimeout(redirect, 1500);
    })();
</script>
@endsection
