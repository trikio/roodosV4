<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class CleanLandingsCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/landings-clean-test-' . uniqid();
        mkdir($this->tmp . '/storage/app', 0755, true);
        $this->app->useStoragePath($this->tmp . '/storage');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));

        parent::tearDown();
    }

    private function row(int $id, string $slug, string $title, array $breadcrumb = [], int $make = 0, string $redirect = ''): array
    {
        return [
            'id' => (string) $id, 'slug' => $slug, 'title' => $title, 'redirect' => $redirect,
            'content' => $breadcrumb ? json_encode(['breadcrumb' => $breadcrumb]) : '',
            'id_car_make' => (string) $make, 'id_car_model' => '0',
        ];
    }

    /**
     * @return array{sql: string, removed: string}
     */
    private function runCleanup(array $rows, array $adsByTerms): array
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('carVocabulary')->with('pe')->andReturn([
            'makes' => ['honda', 'chevrolet', 'audi', 'toyota'],
            'models' => ['honda-civic', 'chevrolet-aveo', 'audi-100'],
            'places' => ['Lima', 'Lima Metropolitana'],
        ]);
        $manticore->shouldReceive('iterateCarLandings')->with('pe')->andReturnUsing(fn () => (fn () => yield from $rows)());
        $manticore->shouldReceive('countCars')
            ->withArgs(fn ($index, $options, $allTerms) => $index === 'car_pe' && $allTerms)
            ->andReturnUsing(fn ($index, $options) => $adsByTerms[$options['q']] ?? 0);
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->artisan('landings:clean', ['country' => 'pe', '--output' => $this->tmp])->assertSuccessful();

        return [
            'sql' => file_get_contents(glob($this->tmp . '/*.sql')[0]),
            'removed' => file_get_contents($this->tmp . '/storage/app/sitemaps/pe/removed-1.xml'),
        ];
    }

    public function test_keeps_only_real_searches_with_ads(): void
    {
        $honda = ['make' => 'honda'];
        ['sql' => $sql, 'removed' => $removed] = $this->runCleanup([
            $this->row(1, 'honda-ecuador', 'Honda Ecuador', $honda, 76),
            $this->row(2, 'autos-honda-usados', 'Autos Honda usados', $honda, 76),
            $this->row(3, 'autos-honda', 'Autos Honda', $honda, 76),
            $this->row(4, 'camionetas-honda', 'Camionetas Honda', $honda, 76),
            $this->row(5, 'honda-0987654321', 'Honda 0987654321', $honda, 76),
            $this->row(6, 'dinero-groupfinancialcentrumcz', 'dinero groupfinancial@centrum.cz'),
            $this->row(7, 'chevrolet-optra-2005-excelentes', 'Chevrolet Optra 2005 excelentes'),
            $this->row(8, 'honda', 'Honda', $honda, 76, '/marca/honda'),
            $this->row(9, 'autos-usados-ecuador', 'Autos usados Ecuador'),
            $this->row(10, 'autos-honda-civic-usados-lima', 'Autos Honda Civic usados Lima'),
            $this->row(11, 'autos-chevrolet-usados', 'Autos Chevrolet usados'),
            $this->row(12, 'autos-honda-civic-usados', 'Autos Honda Civic usados'),
            $this->row(13, 'doble-traccion-si-inmovilizador', 'Doble tracción Sí Inmovilizador'),
            $this->row(14, 'autos-lima', 'Autos Lima', ['make' => 'audi'], 10),
        ], [
            'honda' => 50, 'chevrolet' => 30, 'civic honda' => 20, 'civic honda lima' => 2, 'lima' => 12,
        ]);

        $this->assertStringContainsString('CREATE TABLE car_landings_backup_pe_', $sql);
        $this->assertStringContainsString('START TRANSACTION;', $sql);
        $this->assertStringContainsString('COMMIT;', $sql);

        // Duplicates, and junk naming a make, go to the landing that stays.
        $this->assertStringContainsString("SET redirect = '/autos-honda-usados' WHERE country_id = 'pe' AND redirect = '' AND id IN (1,3,5);", $sql);
        // Ad text with known words goes to the closest kept landing.
        $this->assertStringContainsString("SET redirect = '/autos-chevrolet-usados' WHERE country_id = 'pe' AND redirect = '' AND id IN (7);", $sql);
        // A real search with too few ads goes one level up (model without the city).
        $this->assertStringContainsString("SET redirect = '/autos-honda-civic-usados' WHERE country_id = 'pe' AND redirect = '' AND id IN (10);", $sql);
        // No real search and nothing above it: deactivated, so the page returns 410.
        $this->assertStringContainsString("SET activo = 0 WHERE country_id = 'pe' AND redirect = '' AND id IN (6,13);", $sql);
        // A kept landing whose ids the slug does not confirm gets them reset.
        $this->assertStringContainsString("SET id_car_make = 0, id_car_model = 0 WHERE country_id = 'pe' AND id IN (14);", $sql);
        // An old redirect to the make page now goes straight to its landing.
        $this->assertStringContainsString("SET redirect = '/autos-honda-usados' WHERE country_id = 'pe' AND redirect LIKE '/m%' AND id IN (8);", $sql);

        // Camionetas are a different search, and country-wide landings are left alone.
        $this->assertDoesNotMatchRegularExpression('/id IN \([^)]*\b(4|9|2|11|12)\b/', $sql);

        // Removed URLs are listed for recrawl; kept ones are not.
        $this->assertStringContainsString('<loc>https://autos.roodos.pe/honda-ecuador</loc>', $removed);
        $this->assertStringContainsString('<loc>https://autos.roodos.pe/doble-traccion-si-inmovilizador</loc>', $removed);
        $this->assertStringNotContainsString('autos-honda-usados<', $removed);
    }

    public function test_main_landing_keeps_the_plural_type_and_condition_shape(): void
    {
        ['sql' => $sql] = $this->runCleanup([
            $this->row(1, 'usados-lima-toyota', 'usados Lima Toyota'),
            $this->row(2, 'autos-toyota-usados-lima', 'Autos Toyota usados Lima'),
            $this->row(3, 'autos-lima-usado', 'Autos Lima usado'),
            $this->row(4, 'autos-lima', 'Autos Lima'),
            $this->row(5, 'peru-autos', 'Perú Autos'),
            $this->row(6, 'autos-usados-peru', 'Autos usados Perú'),
            $this->row(7, 'camion-camioneta-marca', 'Camion Camioneta Marca'),
        ], ['lima toyota' => 20, 'lima' => 50]);

        // The city goes last, so "ends with usados" is not required.
        $this->assertStringContainsString("SET redirect = '/autos-toyota-usados-lima' WHERE country_id = 'pe' AND redirect = '' AND id IN (1);", $sql);
        $this->assertStringContainsString("SET redirect = '/autos-lima' WHERE country_id = 'pe' AND redirect = '' AND id IN (3);", $sql);
        // Country-wide landings are deduplicated too, one per type and condition.
        $this->assertStringContainsString("SET redirect = '/autos-usados-peru' WHERE country_id = 'pe' AND redirect = '' AND id IN (5);", $sql);
        $this->assertDoesNotMatchRegularExpression('/id IN \\([^)]*\\b(2|4|6|7)\\b/', $sql);
    }
}
