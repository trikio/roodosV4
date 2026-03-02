<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarsController;

// Rutas con dominio específico para autos
Route::domain('autos.roodos.{country}')->group(function () {
    // Home page
    Route::get('/', [CarsController::class, 'home'])->name('cars.home');

    // Ruta para detalle de auto
    Route::get('/auto/{id}', [CarsController::class, 'show'])->name('cars.show');

    // Ruta catch-all para landing pages con slug
    Route::get('/{slug}', [CarsController::class, 'landing'])->where('slug', '.*')->name('cars.landing');
});

// Dominio de desarrollo local para autos (apunta a Ecuador)
Route::domain('autos.roodos.local')->group(function () {
    Route::get('/', function() {
        return app(CarsController::class)->home('autos', 'ec');
    })->name('cars.home.dev');

    Route::get('/auto/{id}', function($id) {
        return app(CarsController::class)->show('autos', 'ec', $id);
    })->name('cars.show.dev');

    Route::get('/{slug}', function($slug) {
        return app(CarsController::class)->landing(request(), 'autos', 'ec', $slug);
    })->where('slug', '.*')->name('cars.landing.dev');
});

// Rutas con dominio específico para casas
Route::domain('casas.roodos.{country}')->group(function () {
    // Home page
    Route::get('/', [\App\Http\Controllers\HousesController::class, 'home'])->name('houses.home');

    // Ruta para detalle de casa
    Route::get('/casa/{id}', [\App\Http\Controllers\HousesController::class, 'show'])->name('houses.show');

    // Ruta catch-all para landing pages con slug
    Route::get('/{slug}', [\App\Http\Controllers\HousesController::class, 'landing'])->where('slug', '.*')->name('houses.landing');
});
