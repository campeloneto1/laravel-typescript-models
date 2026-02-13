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
                            {--only= : Generate only specified types (resources,models,requests)}
                            {--include= : Include additional types (models,resources,requests)}
                            {--models : Generate only model interfaces (legacy, use --only=models)}
                            {--resources : Generate only resource interfaces (legacy, use --only=resources)}
                            {--requests : Generate only request interfaces (legacy, use --only=requests)}
                            {--yup : Generate Yup schemas}
                            {--zod : Generate Zod schemas}
                            {--no-paginated : Skip paginated types}
                            {--no-array-types : Skip array types}
                            {--split-by= : Split output by domain (subdirectory, class)}';

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
        $only = $this->option('only');
        $include = $this->option('include');
        $splitBy = $this->option('split-by');

        // Legacy flags (for backwards compatibility)
        $legacyModels = $this->option('models');
        $legacyResources = $this->option('resources');
        $legacyRequests = $this->option('requests');

        $generateYup = $this->option('yup');
        $generateZod = $this->option('zod');
        $noPaginated = $this->option('no-paginated');
        $noArrayTypes = $this->option('no-array-types');

        // Store original config for restoration
        $originalConfig = [
            'include_models' => config('typescript-models.include_models'),
            'include_resources' => config('typescript-models.include_resources'),
            'include_requests' => config('typescript-models.include_requests'),
            'generate_yup_schemas' => config('typescript-models.generate_yup_schemas'),
            'generate_zod_schemas' => config('typescript-models.generate_zod_schemas'),
        ];

        // Determine what to include based on --only, --include, and legacy flags
        $includeModels = config('typescript-models.include_models', false);
        $includeResources = config('typescript-models.include_resources', true);
        $includeRequests = config('typescript-models.include_requests', true);

        // Handle legacy flags (--models, --resources, --requests)
        if ($legacyModels || $legacyResources || $legacyRequests) {
            $includeModels = $legacyModels;
            $includeResources = $legacyResources;
            $includeRequests = $legacyRequests;
        }

        // Handle --only flag (overrides everything)
        if ($only) {
            $onlyTypes = array_map('trim', explode(',', $only));
            $includeModels = in_array('models', $onlyTypes);
            $includeResources = in_array('resources', $onlyTypes);
            $includeRequests = in_array('requests', $onlyTypes);
        }

        // Handle --include flag (adds to current selection)
        if ($include) {
            $includeTypes = array_map('trim', explode(',', $include));
            if (in_array('models', $includeTypes)) {
                $includeModels = true;
            }
            if (in_array('resources', $includeTypes)) {
                $includeResources = true;
            }
            if (in_array('requests', $includeTypes)) {
                $includeRequests = true;
            }
        }

        // Apply configuration
        config([
            'typescript-models.include_models' => $includeModels,
            'typescript-models.include_resources' => $includeResources,
            'typescript-models.include_requests' => $includeRequests,
            'typescript-models.generate_yup_schemas' => $generateYup ?: config('typescript-models.generate_yup_schemas'),
            'typescript-models.generate_zod_schemas' => $generateZod ?: config('typescript-models.generate_zod_schemas'),
            'typescript-models.include_paginated_types' => !$noPaginated,
            'typescript-models.include_array_types' => !$noArrayTypes,
        ]);

        try {
            // Handle split-by-domain generation
            if ($splitBy) {
                $files = $service->generateSplitByDomain($splitBy);

                // Determine output directory
                $outputDir = pathinfo($outputPath, PATHINFO_EXTENSION)
                    ? dirname($outputPath)
                    : $outputPath;

                if (!File::isDirectory($outputDir)) {
                    File::makeDirectory($outputDir, 0755, true);
                }

                // Write each file
                foreach ($files as $filename => $content) {
                    $filePath = rtrim($outputDir, '/') . '/' . $filename;
                    File::put($filePath, $content);
                }

                $this->info("TypeScript interfaces generated successfully!");
                $this->info("Output directory: {$outputDir}");
                $this->info("Files generated: " . count($files));

                $this->newLine();
                $this->table(
                    ['File', 'Size'],
                    array_map(fn($f, $c) => [$f, strlen($c) . ' bytes'], array_keys($files), array_values($files))
                );
            } else {
                // Single file generation (default)
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
            }

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
            foreach ($originalConfig as $key => $value) {
                config(["typescript-models.{$key}" => $value]);
            }
        }
    }
}
