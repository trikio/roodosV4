<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarsController;
use App\Http\Controllers\HousesController;

$infoPages = [
    'envie-su-sitio' => 'pages.submit-site',
    'sobre-nosotros' => 'pages.about',
    'terminos-de-uso' => 'pages.terms',
    'politica-de-privacidad' => 'pages.privacy',
    'politica-de-cookies' => 'pages.cookies',
    'nuestras-redes' => 'pages.social',
    'contacta-con-nosotros' => 'pages.contact',
];

Route::domain('roodos.{country}')->group(function () {
    Route::get('/', function (string $country) {
        $countryCode = strtoupper($country);
        $countryName = config('countries.names.' . $countryCode, $countryCode);

        return view('pages.country-home', compact('country', 'countryCode', 'countryName'));
    })->name('country.home');
});

Route::domain('www.roodos.{country}')->group(function () {
    Route::get('/{path?}', function (Request $request, string $country, ?string $path = null) {
        $target = 'https://autos.roodos.' . $country;

        if (!empty($path)) {
            $target .= '/' . ltrim($path, '/');
        }

        $query = $request->getQueryString();
        if (!empty($query)) {
            $target .= '?' . $query;
        }

        return redirect()->away($target, 301);
    })->where('path', '.*');
});

// Rutas con dominio específico para autos
Route::domain('autos.roodos.{country}')->group(function () use ($infoPages) {
    // Home page
    Route::get('/', [CarsController::class, 'home'])->name('cars.home');

    // Search/Index page
    Route::get('/search', [CarsController::class, 'index'])->name('cars.index');

    // Ruta para detalle de auto
    Route::get('/auto/{id}', [CarsController::class, 'show'])->name('cars.show');

    // Páginas informativas
    foreach ($infoPages as $slug => $view) {
        Route::view('/' . $slug, $view);
    }

    // Ruta catch-all para landing pages con slug
    Route::get('/{slug}', [CarsController::class, 'landing'])->where('slug', '.*')->name('cars.landing');
});


// Rutas con dominio específico para casas
Route::domain('casas.roodos.{country}')->group(function () use ($infoPages) {
    // Home page
    Route::get('/', [HousesController::class, 'home'])->name('houses.home');

    // Search/Index page
    Route::get('/search', [HousesController::class, 'index'])->name('houses.index');

    // Ruta para detalle de casa
    Route::get('/casa/{id}', [HousesController::class, 'show'])->name('houses.show');

    // Páginas informativas
    foreach ($infoPages as $slug => $view) {
        Route::view('/' . $slug, $view);
    }

    // Ruta catch-all para landing pages con slug
    Route::get('/{slug}', [HousesController::class, 'landing'])->where('slug', '.*')->name('houses.landing');
});
