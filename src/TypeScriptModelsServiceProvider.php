<?php

namespace Campelo\LaravelTypescriptModels;

use Campelo\LaravelTypescriptModels\Console\Commands\GenerateTypescriptCommand;
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
        $this->registerCommands();
    }

    /**
     * Register the package commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateTypescriptCommand::class,
            ]);
        }
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        $middleware = config('typescript-models.middleware', ['api']);
        $route = config('typescript-models.route', '/api/typescript-models');

        Route::middleware($middleware)
            ->get($route, TypeScriptGeneratorController::class)
            ->name('typescript-models.generate');

        Route::middleware($middleware)
            ->get($route . '/configurator', [TypeScriptGeneratorController::class, 'configurator'])
            ->name('typescript-models.configurator');
    }
}
