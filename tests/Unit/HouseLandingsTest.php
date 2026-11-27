<?php

namespace Tests\Unit;

use App\Support\HouseLandings;
use PHPUnit\Framework\TestCase;

class HouseLandingsTest extends TestCase
{
    public function test_titles_read_as_searches(): void
    {
        $this->assertSame('Departamentos en venta', HouseLandings::title('Venta', 'Departamento'));
        $this->assertSame('Casas en renta en Mérida', HouseLandings::title('Renta', 'Casa', 'Mérida'));
        $this->assertSame('Naves industriales en renta', HouseLandings::title('Renta', 'Nave Industrial'));
        $this->assertSame('Locales en alquiler', HouseLandings::title('Alquiler', 'Local'));
    }

    public function test_only_landings_created_from_the_inventory_have_filters(): void
    {
        $this->assertSame(
            ['operation' => 1, 'type' => 2, 'state' => '', 'city' => 15],
            HouseLandings::generatedFilters('{"generated":{"operation":1,"type":2,"city":15,"created":"2026-11-27"}}')
        );
        $this->assertNull(HouseLandings::generatedFilters(null));
        $this->assertNull(HouseLandings::generatedFilters('{"breadcrumb":{}}'));
    }
}
