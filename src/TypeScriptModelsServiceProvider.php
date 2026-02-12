<?php

namespace Campelo\LaravelTypescriptModels;

use Campelo\LaravelTypescriptModels\Http\Controllers\TypeScriptGeneratorController;
use Campelo\LaravelTypescriptModels\Services\ModelToTypeScriptService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TypeScriptModelsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/typescript-models.php',
            'typescript-models'
        );

        $this->app->singleton(ModelToTypeScriptService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/typescript-models.php' => config_path('typescript-models.php'),
        ], 'typescript-models-config');

        $this->registerRoutes();
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(config('typescript-models.middleware', ['api']))
            ->get(
                config('typescript-models.route', '/api/typescript-models'),
                TypeScriptGeneratorController::class
            )
            ->name('typescript-models.generate');
    }
}
