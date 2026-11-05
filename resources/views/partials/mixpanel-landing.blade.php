{{-- Evento "Landing Viewed" de las landings, marcas y modelos; app.js lo envía al cargar Mixpanel. --}}
@php
    $landingEvent = [
        'vertical' => $vertical,
        'country' => $country,
        'landing_type' => match (true) {
            request()->routeIs('*.brand') => 'marca',
            request()->routeIs('*.model') => 'modelo',
            default => 'landing',
        },
        'slug' => (string) $slugData['original'],
        'title' => $slugData['title'],
        'results_count' => $results->total(),
        'page' => $results->currentPage(),
        'widened' => !empty($slugData['widened']),
    ];
@endphp
<script>
    (window.mixpanelEvents = window.mixpanelEvents || []).push(['Landing Viewed', @json($landingEvent)]);
</script>
