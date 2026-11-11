<?php

namespace App\Http\Controllers;

use App\Services\LandingLinks;
use App\Support\Guides;
use Illuminate\Support\Str;

class GuidesController extends Controller
{
    private const COUNTRY_NAMES = ['cl' => 'Chile', 'mx' => 'México', 'pe' => 'Perú', 'ec' => 'Ecuador'];

    public function __construct(private LandingLinks $links)
    {
    }

    public function index(string $country)
    {
        $country = $this->countryCode($country);
        $guides = Guides::all($country);
        abort_if($guides === [], 404);

        return view('guides.index', [
            'guides' => $guides,
            'country' => $country,
            'countryName' => self::COUNTRY_NAMES[$country],
            'lang' => $this->lang($country),
        ]);
    }

    public function show(string $country, string $slug)
    {
        $country = $this->countryCode($country);
        $guide = Guides::find($country, $slug);
        abort_if($guide === null, 404);

        // The same make links as the home: the make's landing when it exists, otherwise its page.
        $makeLinks = [];
        foreach ($this->links->landingUrls($country, LandingLinks::MAIN_MAKES) as $label => $url) {
            $makeLinks[] = ['label' => $label, 'url' => $url ?? url('/marca/' . Str::slug($label))];
        }

        return view('guides.show', [
            'guide' => $guide,
            'country' => $country,
            'countryName' => self::COUNTRY_NAMES[$country],
            'lang' => $this->lang($country),
            'makeLinks' => $makeLinks,
        ]);
    }

    /** Only the countries with a site have guides; anything else (lab included) is a 404. */
    private function countryCode(string $country): string
    {
        $country = strtolower($country);
        abort_unless(isset(self::COUNTRY_NAMES[$country]), 404);

        return $country;
    }

    private function lang(string $country): string
    {
        return 'es-' . strtoupper($country);
    }
}
