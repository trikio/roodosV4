<?php

namespace Tests\Unit;

use App\Services\ManticoreSearchService;
use App\Support\LandingVocabulary;
use Mockery;
use Tests\TestCase;

class LandingVocabularyTest extends TestCase
{
    private function vocabulary(): LandingVocabulary
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('carVocabulary')->andReturn([
            'makes' => ['chevrolet', 'mercedes-benz', 'kia', 'bmw', 'seat'],
            'models' => ['chevrolet-aveo', 'chevrolet-aveo-sedan', 'kia-rio-sedan', 'mercedes-benz-clase-c', 'bmw-serie-1', 'kia-1', 'seat-leon'],
            'places' => ['Quito', 'Viña del Mar', 'León'],
        ]);

        return LandingVocabulary::forCountry($manticore, 'ec');
    }

    public function test_models_count_when_they_belong_to_the_named_make(): void
    {
        $parts = $this->vocabulary()->split(['chevrolet', 'aveo', 'sedan', '2012', 'quito']);

        $this->assertSame(['chevrolet'], $parts['make']);
        $this->assertSame(['aveo', 'sedan'], $parts['model']);
        $this->assertSame(['2012'], $parts['year']);
        $this->assertSame(['quito'], $parts['place']);
        $this->assertSame([], $parts['unknown']);
    }

    public function test_multi_word_makes(): void
    {
        $parts = $this->vocabulary()->split(['mercedes', 'benz', 'clase', 'c']);

        $this->assertSame(['mercedes', 'benz'], $parts['make']);
        $this->assertSame(['clase', 'c'], $parts['model']);
    }

    public function test_a_model_without_make_implies_its_only_make(): void
    {
        $vocabulary = $this->vocabulary();

        $this->assertSame(['chevrolet'], $vocabulary->split(['aveo'])['implied_make']);
        $this->assertTrue($vocabulary->isRealSearch(['aveo']));
    }

    public function test_words_shared_by_many_makes_or_bare_numbers_are_not_models(): void
    {
        $vocabulary = $this->vocabulary();

        // "sedan" is a Chevrolet and a Kia model word, "1" a BMW and Kia one.
        $this->assertSame(['sedan', '2006'], array_merge($vocabulary->split(['sedan', '2006'])['unknown'], $vocabulary->split(['sedan', '2006'])['year']));
        $this->assertFalse($vocabulary->isRealSearch(['sedan', '2006']));
        $this->assertFalse($vocabulary->isRealSearch(['1']));
    }

    public function test_a_year_alone_is_not_a_search(): void
    {
        $this->assertFalse($this->vocabulary()->isRealSearch(['2007']));
    }

    public function test_places_and_the_named_make_take_priority_over_model_words(): void
    {
        $vocabulary = $this->vocabulary();

        // "leon" is a city and a Seat model: a city unless the title names Seat.
        $this->assertSame(['leon'], $vocabulary->split(['leon'])['place']);
        $this->assertSame(['leon'], $vocabulary->split(['seat', 'leon'])['model']);
        $this->assertSame(['vina', 'mar'], $vocabulary->split(['vina', 'mar'])['place']);
    }
}
