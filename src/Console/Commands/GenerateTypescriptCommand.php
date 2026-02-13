<?php

namespace Campelo\LaravelTypescriptModels\Console\Commands;

use Campelo\LaravelTypescriptModels\Services\ModelToTypeScriptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateTypescriptCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'typescript:generate
                            {--output= : Output file path (default: resources/types/api.d.ts)}
                            {--models : Generate only model interfaces}
                            {--resources : Generate only resource interfaces}
                            {--requests : Generate only request interfaces}
                            {--yup : Generate Yup schemas}
                            {--zod : Generate Zod schemas}
                            {--no-paginated : Skip paginated types}
                            {--no-array-types : Skip array types}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate TypeScript interfaces from Laravel models, resources, and requests';

    /**
     * Execute the console command.
     */
    public function handle(ModelToTypeScriptService $service): int
    {
        $this->info('Generating TypeScript interfaces...');

        // Get options
        $outputPath = $this->option('output') ?? resource_path('types/api.d.ts');
        $onlyModels = $this->option('models');
        $onlyResources = $this->option('resources');
        $onlyRequests = $this->option('requests');
        $generateYup = $this->option('yup');
        $generateZod = $this->option('zod');
        $noPaginated = $this->option('no-paginated');
        $noArrayTypes = $this->option('no-array-types');

        // If specific options are set, temporarily override config
        $originalConfig = [];

        if ($onlyModels || $onlyResources || $onlyRequests) {
            $originalConfig = [
                'include_resources' => config('typescript-models.include_resources'),
                'include_requests' => config('typescript-models.include_requests'),
                'generate_yup_schemas' => config('typescript-models.generate_yup_schemas'),
                'generate_zod_schemas' => config('typescript-models.generate_zod_schemas'),
            ];

            config([
                'typescript-models.include_resources' => $onlyResources || (!$onlyModels && !$onlyRequests),
                'typescript-models.include_requests' => $onlyRequests || (!$onlyModels && !$onlyResources),
                'typescript-models.generate_yup_schemas' => $generateYup,
                'typescript-models.generate_zod_schemas' => $generateZod,
            ]);
        }

        // Override schema generation if explicitly requested
        if ($generateYup) {
            config(['typescript-models.generate_yup_schemas' => true]);
        }
        if ($generateZod) {
            config(['typescript-models.generate_zod_schemas' => true]);
        }

        // Set pagination and array type options
        config([
            'typescript-models.include_paginated_types' => !$noPaginated,
            'typescript-models.include_array_types' => !$noArrayTypes,
        ]);

        try {
            $content = $service->generate();

            // Ensure directory exists
            $directory = dirname($outputPath);
            if (!File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            // Write file
            File::put($outputPath, $content);

            $this->info("TypeScript interfaces generated successfully!");
            $this->info("Output: {$outputPath}");

            // Show summary
            $this->newLine();
            $this->table(
                ['Type', 'Count'],
                [
                    ['Models', count($service->getDiscoveredModels())],
                    ['Resources', count($service->getDiscoveredResources())],
                    ['Requests', count($service->getDiscoveredRequests())],
                ]
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Error generating TypeScript interfaces: {$e->getMessage()}");
            return Command::FAILURE;
        } finally {
            // Restore original config
            if (!empty($originalConfig)) {
                foreach ($originalConfig as $key => $value) {
                    config(["typescript-models.{$key}" => $value]);
                }
            }
        }
    }
}
