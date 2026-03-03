<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarsController;

// Rutas con dominio específico para autos
Route::domain('autos.roodos.{country}')->group(function () {
    // Home page
    Route::get('/', [CarsController::class, 'home'])->name('cars.home');

    // Search/Index page
    Route::get('/search', [CarsController::class, 'index'])->name('cars.index');

    // Ruta para detalle de auto
    Route::get('/auto/{id}', [CarsController::class, 'show'])->name('cars.show');

    // Ruta catch-all para landing pages con slug
    Route::get('/{slug}', [CarsController::class, 'landing'])->where('slug', '.*')->name('cars.landing');
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
