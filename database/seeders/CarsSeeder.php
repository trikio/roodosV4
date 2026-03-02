<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Car;

class CarsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cars = [
            [
                'brand' => 'Honda',
                'model' => 'Civic',
                'title' => 'Honda Civic Sedan Ex 2.0 Aut 2016 bencina 2.0 Vitacura',
                'price' => 11000000,
                'description' => 'Honda Civic Ferio - Vitacura - 2016 - Gasolina - 146000 kms. tracción delantera, color azul, cilindrada 2 L, 146.000 kilómetros, versión Sedan Ex 2.0 Aut, transmisión automática, motor 2.0, combustible bencina.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Honda+Civic',
                'year' => 2016,
                'kilometers' => 146000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Honda',
                'model' => 'Civic',
                'title' => 'Honda Civic SEDÁN EX 2.0 AUT. usado automático 146.000 kilómetros Las Condes',
                'price' => 11000000,
                'description' => 'Honda Civic Ferio - Las Condes - 2016 - Gasolina - 146000 kms. tracción delantera, transmisión automática, color azul, versión SEDÁN EX 2.0 AUT., cilindrada 2 L.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Honda+Civic+2',
                'year' => 2016,
                'kilometers' => 146000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Honda',
                'model' => 'Civic',
                'title' => 'Honda Civic EXL 1.8 AT usado Delantera bencina',
                'price' => 5990000,
                'description' => 'Honda Civic EXL 1.8 AT usado Delantera bencina. Transmisión automática, color plata, motor 1.8, versión EXL.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Honda+Civic+3',
                'year' => 2015,
                'kilometers' => 120000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'title' => 'Toyota Corolla 2018 automático',
                'price' => 8500000,
                'description' => 'Toyota Corolla 2018 automático en perfecto estado. Color blanco perla, motor 1.8, cilindrada 1800cc, transmisión automática.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Toyota+Corolla',
                'year' => 2018,
                'kilometers' => 95000,
                'transmission' => 'Automática',
                'location' => 'Región del Libertador General',
                'condition' => 'used',
            ],
            [
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'title' => 'Toyota Corolla 2017 1.6 manual',
                'price' => 7200000,
                'description' => 'Toyota Corolla 2017, motor 1.6, transmisión manual. Color gris, buen estado de carrocería.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Toyota+Corolla+2',
                'year' => 2017,
                'kilometers' => 110000,
                'transmission' => 'Manual',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Hyundai',
                'model' => 'Accent',
                'title' => 'Hyundai Accent 2015 automático',
                'price' => 5500000,
                'description' => 'Hyundai Accent 2015, automático, color rojo, motor 1.4, buen estado general.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Hyundai+Accent',
                'year' => 2015,
                'kilometers' => 135000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Chevrolet',
                'model' => 'Cruze',
                'title' => 'Chevrolet Cruze 2016 automático',
                'price' => 8200000,
                'description' => 'Chevrolet Cruze 2016, automático, motor 1.8, color negro, transmisión automática, en excelente estado.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Chevrolet+Cruze',
                'year' => 2016,
                'kilometers' => 105000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Kia',
                'model' => 'Forte',
                'title' => 'Kia Forte 2017 automático',
                'price' => 7800000,
                'description' => 'Kia Forte 2017, automático, color azul, motor 1.6, transmisión automática, documentos al día.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Kia+Forte',
                'year' => 2017,
                'kilometers' => 98000,
                'transmission' => 'Automática',
                'location' => 'Región del Libertador General',
                'condition' => 'used',
            ],
            [
                'brand' => 'Nissan',
                'model' => 'Sentra',
                'title' => 'Nissan Sentra 2018 automático',
                'price' => 8900000,
                'description' => 'Nissan Sentra 2018, automático, color plateado, motor 1.8, buen estado general, llantas nuevas.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Nissan+Sentra',
                'year' => 2018,
                'kilometers' => 85000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Volkswagen',
                'model' => 'Golf',
                'title' => 'Volkswagen Golf 2015 manual',
                'price' => 7500000,
                'description' => 'Volkswagen Golf 2015, transmisión manual, motor 1.6, color rojo, estado de uso normal.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Volkswagen+Golf',
                'year' => 2015,
                'kilometers' => 125000,
                'transmission' => 'Manual',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Honda',
                'model' => 'CR-V',
                'title' => 'Honda CR-V 2016 automático',
                'price' => 14500000,
                'description' => 'Honda CR-V 2016, automático, color gris, motor 2.4, suv con tracción en las 4 ruedas, estado excelente.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Honda+CRV',
                'year' => 2016,
                'kilometers' => 100000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
            [
                'brand' => 'Toyota',
                'model' => 'RAV4',
                'title' => 'Toyota RAV4 2017 automático',
                'price' => 16800000,
                'description' => 'Toyota RAV4 2017, automático, color negro, motor 2.5, suv con tracción integral, excelente condición.',
                'image_url' => 'https://via.placeholder.com/400x300?text=Toyota+RAV4',
                'year' => 2017,
                'kilometers' => 92000,
                'transmission' => 'Automática',
                'location' => 'Región Metropolitana',
                'condition' => 'used',
            ],
        ];

        foreach ($cars as $car) {
            Car::create($car);
        }
    }
}
