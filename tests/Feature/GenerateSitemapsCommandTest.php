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
        $manticore->shouldReceive('iterateHouseLandings')->with('pe')->andReturn((fn () => yield from [
            ['id' => '1', 'slug' => 'casas-en-venta-en-arequipa', 'content' => '{"generated":{"operation":1,"type":1,"state":4}}'],
            ['id' => '2', 'slug' => 'casa-lima', 'content' => ''],
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

    public function test_sitemap_index_stays_empty_until_cleanup_is_applied(): void
    {
        file_put_contents($this->tmp . '/app/sitemaps/pe/removed-1.xml', '<urlset/>');

        $this->get('http://autos.roodos.pe/sitemap.xml')->assertOk()->assertDontSee('removed-1.xml');
        $this->get('http://autos.roodos.pe/sitemaps/removed-1.xml')->assertNotFound();

        touch($this->tmp . '/app/sitemaps/pe/.limpieza-aplicada');

        $this->get('http://autos.roodos.pe/sitemap.xml')->assertSee('https://autos.roodos.pe/sitemaps/removed-1.xml');
        $this->get('http://autos.roodos.pe/sitemaps/removed-1.xml')->assertOk();
    }

    public function test_lists_active_landings_once_cleanup_is_applied(): void
    {
        touch($this->tmp . '/app/sitemaps/pe/.limpieza-aplicada');

        $this->artisan('sitemaps:generate', ['country' => 'pe'])->assertSuccessful();

        $sitemap = file_get_contents($this->tmp . '/app/sitemaps/pe/landings-1.xml');
        $this->assertStringContainsString('<loc>https://autos.roodos.pe/autos-honda-usados</loc>', $sitemap);
        $this->assertStringNotContainsString('/honda<', $sitemap);
    }

    public function test_house_sitemap_lists_generated_landings_without_waiting_for_the_cleanup(): void
    {
        $this->artisan('sitemaps:generate', ['country' => 'pe'])->assertSuccessful();

        $this->assertSame([], glob($this->tmp . '/app/sitemaps/pe/landings-*.xml'));
        $sitemap = file_get_contents($this->tmp . '/app/sitemaps/pe/casas-1.xml');
        $this->assertStringContainsString('<loc>https://casas.roodos.pe/casas-en-venta-en-arequipa</loc>', $sitemap);
        $this->assertStringNotContainsString('casa-lima', $sitemap);

        $this->get('http://casas.roodos.pe/sitemap.xml')->assertOk()->assertSee('https://casas.roodos.pe/sitemaps/casas-1.xml');
        $this->get('http://casas.roodos.pe/sitemaps/casas-1.xml')->assertOk();
    }
}
