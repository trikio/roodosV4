{{-- Internal links: models of a make, and related landings (see App\Services\LandingLinks). --}}
@if(!empty($modelLinks))
<section class="mt-10">
    <h2 class="text-lg font-bold text-gray-900 mb-3">Modelos de {{ $slugData['title'] ?? '' }}</h2>
    <ul class="flex flex-wrap gap-2">
        @foreach($modelLinks as $link)
        <li>
            <a href="{{ $link['url'] }}" class="inline-block px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-full text-gray-700 hover:border-[#008bea] hover:text-[#008bea]">{{ $link['title'] }}</a>
        </li>
        @endforeach
    </ul>
</section>
@endif

@if(!empty($landingLinks['links']))
<section class="mt-10">
    <h2 class="text-lg font-bold text-gray-900 mb-3">{{ $landingLinks['title'] }}</h2>
    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
        @foreach($landingLinks['links'] as $link)
        <li>
            <a href="{{ $link['url'] }}" class="text-sm text-[#008bea] hover:underline">{{ $link['title'] }}</a>
        </li>
        @endforeach
    </ul>
</section>
@endif
