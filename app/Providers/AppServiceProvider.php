<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Cloudflare terminates TLS and reaches the server over http: without this, redirects
        // and canonical URLs came out as http:// and cost an extra hop.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
