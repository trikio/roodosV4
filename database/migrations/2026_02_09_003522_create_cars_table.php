<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('model');
            $table->string('title');
            $table->decimal('price', 12, 2);
            $table->text('description');
            $table->string('image_url')->nullable();
            $table->integer('year');
            $table->integer('kilometers');
            $table->string('transmission');
            $table->string('location');
            $table->string('condition')->default('used');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
