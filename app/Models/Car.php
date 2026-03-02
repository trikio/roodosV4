<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Car extends Model
{
    protected $fillable = [
        'brand',
        'model',
        'title',
        'price',
        'description',
        'image_url',
        'year',
        'kilometers',
        'transmission',
        'location',
        'condition',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'year' => 'integer',
        'kilometers' => 'integer',
    ];
}
