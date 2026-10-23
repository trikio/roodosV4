<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class GenerateSitemapsCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/sitemaps-test-' . uniqid();
        mkdir($this->tmp . '/app/sitemaps/pe', 0755, true);
        $this->app->useStoragePath($this->tmp);

        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('iterateCarLandings')->with('pe')->andReturn((fn () => yield from [
            ['id' => '1', 'slug' => 'autos-honda-usados', 'redirect' => ''],
            ['id' => '2', 'slug' => 'honda', 'redirect' => '/marca/honda'],
        ])());
        $this->app->instance(ManticoreSearchService::class, $manticore);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));

        parent::tearDown();
    }

    public function test_skips_countries_whose_cleanup_is_not_marked_as_applied(): void
    {
        $this->artisan('sitemaps:generate', ['country' => 'pe'])->assertSuccessful();

        $this->assertSame([], glob($this->tmp . '/app/sitemaps/pe/landings-*.xml'));
    }

    public function test_lists_active_landings_once_cleanup_is_applied(): void
    {
        touch($this->tmp . '/app/sitemaps/pe/.limpieza-aplicada');

        $this->artisan('sitemaps:generate', ['country' => 'pe'])->assertSuccessful();

        $sitemap = file_get_contents($this->tmp . '/app/sitemaps/pe/landings-1.xml');
        $this->assertStringContainsString('<loc>https://autos.roodos.pe/autos-honda-usados</loc>', $sitemap);
        $this->assertStringNotContainsString('/honda<', $sitemap);
    }
}
