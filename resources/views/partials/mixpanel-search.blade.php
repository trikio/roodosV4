{{-- Evento "Search" de las páginas /search; app.js lo envía al cargar Mixpanel. --}}
@php
    $searchEvent = array_merge([
        'vertical' => $vertical,
        'country' => $country,
        'query' => trim((string) request('q', '')),
        'results_count' => $results->total(),
        'page' => $results->currentPage(),
        'order' => $order !== '' ? $order : null,
    ], collect(request()->only($filterKeys))->filter(fn ($value) => filled($value))->all());
@endphp
<script>
    (window.mixpanelEvents = window.mixpanelEvents || []).push(['Search', @json($searchEvent)]);
</script>
