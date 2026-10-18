<?php

namespace App\Support;

use App\Services\ManticoreSearchService;

/**
 * Words a real car search is made of in one country: makes, their models, and the cities and
 * states of the country, plus years. A landing whose title uses other words was made from ad text.
 */
class LandingVocabulary
{
    /**
     * @param array<string, string[]> $makes       make slug => its tokens ("mercedes-benz" => [mercedes, benz])
     * @param array<string, array<string, true>> $modelsByMake make slug => tokens of its models, minus the make's own
     * @param array<string, true> $placeTokens
     */
    /** @var array<string, string[]> token => make slugs containing it */
    private array $makesByToken = [];

    /** @var array<string, string[]> model token => make slugs that have a model with it */
    private array $modelOwners = [];

    public function __construct(
        private array $makes,
        private array $modelsByMake,
        private array $placeTokens,
    ) {
        foreach ($makes as $slug => $tokens) {
            foreach ($tokens as $token) {
                $this->makesByToken[$token][] = $slug;
            }
        }
        foreach ($modelsByMake as $make => $tokens) {
            foreach (array_keys($tokens) as $token) {
                $this->modelOwners[$token][] = $make;
            }
        }
    }

    public static function forCountry(ManticoreSearchService $manticore, string $countryCode): self
    {
        $sources = $manticore->carVocabulary($countryCode);

        $makes = [];
        foreach ($sources['makes'] as $slug) {
            if (($tokens = LandingClassifier::slugTokens($slug)) !== []) {
                $makes[$slug] = $tokens;
            }
        }
        // Longest first, so "mercedes-benz-clase-e" belongs to "mercedes-benz", not "mercedes".
        uksort($makes, fn ($a, $b) => strlen($b) <=> strlen($a));

        $modelsByMake = [];
        foreach ($sources['models'] as $modelSlug) {
            foreach ($makes as $makeSlug => $makeTokens) {
                if (str_starts_with($modelSlug, $makeSlug . '-')) {
                    foreach (array_diff(LandingClassifier::slugTokens($modelSlug), $makeTokens) as $token) {
                        $modelsByMake[$makeSlug][$token] = true;
                    }
                    break;
                }
            }
        }

        $places = array_merge($sources['places'], config('countries.autos_home.locations.' . strtoupper($countryCode), []));
        $placeTokens = [];
        foreach ($places as $place) {
            foreach (LandingClassifier::words($place) as $token) {
                // Generic words ("del" in "Viña del Mar") never mark a title as being about a place.
                if (!in_array($token, LandingClassifier::GENERIC_WORDS, true)) {
                    $placeTokens[$token] = true;
                }
            }
        }

        return new self($makes, $modelsByMake, $placeTokens);
    }

    /**
     * Terms grouped by kind. A model word counts only as a model of a make the terms name, or,
     * when they name none, if it is not a number and belongs to a single make, which then goes to
     * 'implied_make' ("aveo" => chevrolet). Words like "sedan" or "1" exist in many makes' models
     * and say nothing on their own, so they end up in 'unknown'.
     *
     * @return array{make: string[], model: string[], place: string[], year: string[], unknown: string[], implied_make: string[]}
     */
    public function split(array $terms): array
    {
        $parts = ['make' => [], 'model' => [], 'place' => [], 'year' => [], 'unknown' => [], 'implied_make' => []];

        // Candidate makes share a word with the terms; $this->makes order (longest first) is kept.
        $candidates = array_flip(array_merge([], ...array_map(fn ($term) => $this->makesByToken[$term] ?? [], $terms)));
        $namedMakes = [];
        foreach (array_intersect_key($this->makes, $candidates) as $slug => $tokens) {
            if (array_diff($tokens, $terms) === [] && array_diff($tokens, $parts['make']) !== []) {
                $namedMakes[] = $slug;
                $parts['make'] = array_values(array_unique(array_merge($parts['make'], $tokens)));
            }
        }

        $impliedMakes = [];
        foreach ($terms as $term) {
            if (in_array($term, $parts['make'], true)) {
                continue;
            }

            $ofNamedMake = array_filter($namedMakes, fn ($make) => isset($this->modelsByMake[$make][$term]));
            if ($ofNamedMake !== []) {
                $parts['model'][] = $term;
            } elseif (isset($this->placeTokens[$term])) {
                $parts['place'][] = $term;
            } elseif (preg_match('/^(19[5-9]\d|20[0-3]\d)$/', $term)) {
                $parts['year'][] = $term;
            } elseif ($namedMakes === [] && !ctype_digit($term) && count($owners = $this->modelOwners[$term] ?? []) === 1) {
                $parts['model'][] = $term;
                $impliedMakes[$owners[0]] = true;
            } else {
                $parts['unknown'][] = $term;
            }
        }

        foreach (array_keys($impliedMakes) as $make) {
            $parts['implied_make'] = array_merge($parts['implied_make'], $this->makes[$make]);
        }

        return $parts;
    }

    /**
     * A real search names a make (or a model that implies one) or a place, and nothing else
     * than makes, models, places and years.
     */
    public function isRealSearch(array $terms): bool
    {
        $parts = $this->split($terms);

        return $parts['unknown'] === []
            && ($parts['make'] !== [] || $parts['implied_make'] !== [] || $parts['place'] !== []);
    }
}
