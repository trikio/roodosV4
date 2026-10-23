<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // public/build no está versionado; los tests no deben depender de compilar assets.
        $this->withoutVite();
    }
}
