<?php

namespace Tedon\Tooner;

use Illuminate\Support\ServiceProvider;

class ToonerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/tooner.php', 'tooner'
        );

        // Register the main class as a singleton
        $this->app->singleton('tooner', function ($app) {
            return new Tooner(config('tooner'));
        });
    }

    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tooner.php' => config_path('tooner.php'),
            ], 'config');
        }
    }
}