<?php

namespace Campelo\LaravelTypescriptModels\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

class ModelToTypeScriptService
{
    protected array $discoveredModels = [];
    protected array $discoveredResources = [];
    protected array $discoveredRequests = [];
    protected array $modelNames = [];
    protected array $resourceNames = [];
    protected array $requestNames = [];

    /**
     * Get discovered models after generation.
     */
    public function getDiscoveredModels(): array
    {
        return $this->discoveredModels;
    }

    /**
     * Get discovered resources after generation.
     */
    public function getDiscoveredResources(): array
    {
        return $this->discoveredResources;
    }

    /**
     * Get discovered requests after generation.
     */
    public function getDiscoveredRequests(): array
    {
        return $this->discoveredRequests;
    }

    /**
     * Generate TypeScript interfaces for all discovered models and resources.
     */
    public function generate(): string
    {
        // Always discover models for relation resolution, even if not generating them
        $this->discoveredModels = $this->discoverModels();

        // Map class names to short names for relation type resolution
        foreach ($this->discoveredModels as $modelClass) {
            $this->modelNames[$modelClass] = class_basename($modelClass);
        }

        $modelInterfaces = [];
        $modelTypes = [];
        $modelPaginatedTypes = [];

        // Only generate model interfaces if enabled
        if (config('typescript-models.include_models', false)) {
            foreach ($this->discoveredModels as $modelClass) {
                $result = $this->modelToInterface($modelClass);
                if ($result) {
                    $modelInterfaces[] = $result['interface'];
                    $modelTypes[] = $result['type'];
                    $modelPaginatedTypes[] = $result['paginated'];
                }
            }
        }

        // Generate Resource interfaces
        $resourceInterfaces = [];
        $resourceTypes = [];
        $resourcePaginatedTypes = [];

        if (config('typescript-models.include_resources', true)) {
            $this->discoveredResources = $this->discoverResources();

            foreach ($this->discoveredResources as $resourceClass) {
                $this->resourceNames[$resourceClass] = class_basename($resourceClass);
            }

            foreach ($this->discoveredResources as $resourceClass) {
                $result = $this->resourceToInterface($resourceClass);
                if ($result) {
                    $resourceInterfaces[] = $result['interface'];
                    $resourceTypes[] = $result['type'];
                    $resourcePaginatedTypes[] = $result['paginated'];
                }
            }
        }

        $header = $this->generateHeader();
        $paginationInterfaces = $this->generatePaginationInterfaces();

        $output = $header . $paginationInterfaces;

        // Model interfaces
        if (!empty($modelInterfaces)) {
            $output .= "\n// Model Interfaces\n"
                . implode("\n\n", $modelInterfaces)
                . "\n\n// Model Array Types\n"
                . implode("\n", $modelTypes)
                . "\n\n// Model Paginated Types\n"
                . implode("\n", $modelPaginatedTypes)
                . "\n";
        }

        // Resource interfaces
        if (!empty($resourceInterfaces)) {
            $output .= "\n// Resource Interfaces\n"
                . implode("\n\n", $resourceInterfaces)
                . "\n\n// Resource Array Types\n"
                . implode("\n", $resourceTypes)
                . "\n\n// Resource Paginated Types\n"
                . implode("\n", $resourcePaginatedTypes)
                . "\n";
        }

        // Generate Form Request interfaces and validation schemas
        $requestInterfaces = [];
        $yupSchemas = [];
        $zodSchemas = [];

        if (config('typescript-models.include_requests', true)) {
            $this->discoveredRequests = $this->discoverRequests();

            // Build names with conflict detection
            $this->requestNames = $this->buildUniqueNames(
                $this->discoveredRequests,
                'App\\Http\\Requests\\'
            );

            foreach ($this->discoveredRequests as $requestClass) {
                $result = $this->requestToInterface($requestClass);
                if ($result) {
                    $requestInterfaces[] = $result;
                }

                // Generate Yup schema if enabled
                if (config('typescript-models.generate_yup_schemas', true)) {
                    $schema = $this->requestToYupSchema($requestClass);
                    if ($schema) {
                        $yupSchemas[] = $schema;
                    }
                }

                // Generate Zod schema if enabled
                if (config('typescript-models.generate_zod_schemas', false)) {
                    $schema = $this->requestToZodSchema($requestClass);
                    if ($schema) {
                        $zodSchemas[] = $schema;
                    }
                }
            }
        }

        // Request interfaces
        if (!empty($requestInterfaces)) {
            $output .= "\n// Form Request Interfaces\n"
                . implode("\n\n", $requestInterfaces)
                . "\n";
        }

        // Yup schemas
        if (!empty($yupSchemas)) {
            $output .= "\n// Yup Validation Schemas\n"
                . "// Usage: import * as yup from 'yup';\n"
                . implode("\n\n", $yupSchemas)
                . "\n";
        }

        // Zod schemas
        if (!empty($zodSchemas)) {
            $output .= "\n// Zod Validation Schemas\n"
                . "// Usage: import { z } from 'zod';\n"
                . implode("\n\n", $zodSchemas)
                . "\n";
        }

        return $output;
    }

    /**
     * Generate TypeScript interfaces split by domain/module.
     *
     * @param string $mode Detection mode: 'subdirectory' or 'class'
     * @return array<string, string> Map of filename => content
     */
    public function generateSplitByDomain(string $mode = 'subdirectory'): array
    {
        // Discover all classes first
        $this->discoveredModels = $this->discoverModels();
        foreach ($this->discoveredModels as $modelClass) {
            $this->modelNames[$modelClass] = class_basename($modelClass);
        }

        if (config('typescript-models.include_resources', true)) {
            $this->discoveredResources = $this->discoverResources();
            foreach ($this->discoveredResources as $resourceClass) {
                $this->resourceNames[$resourceClass] = class_basename($resourceClass);
            }
        }

        if (config('typescript-models.include_requests', true)) {
            $this->discoveredRequests = $this->discoverRequests();
            $this->requestNames = $this->buildUniqueNames(
                $this->discoveredRequests,
                'App\\Http\\Requests\\'
            );
        }

        // Group classes by domain
        $domainContent = [];
        $allDomains = [];

        // Process models
        if (config('typescript-models.include_models', false)) {
            foreach ($this->discoveredModels as $modelClass) {
                $domain = $this->extractDomain($modelClass, $mode, 'models');
                $allDomains[$domain] = true;

                if (!isset($domainContent[$domain])) {
                    $domainContent[$domain] = [
                        'interfaces' => [],
                        'types' => [],
                        'paginated' => [],
                        'schemas' => [],
                        'dependencies' => [],
                    ];
                }

                $result = $this->modelToInterface($modelClass);
                if ($result) {
                    $domainContent[$domain]['interfaces'][] = $result['interface'];
                    $domainContent[$domain]['types'][] = $result['type'];
                    $domainContent[$domain]['paginated'][] = $result['paginated'];
                }
            }
        }

        // Process resources
        if (config('typescript-models.include_resources', true)) {
            foreach ($this->discoveredResources as $resourceClass) {
                $domain = $this->extractDomain($resourceClass, $mode, 'resources');
                $allDomains[$domain] = true;

                if (!isset($domainContent[$domain])) {
                    $domainContent[$domain] = [
                        'interfaces' => [],
                        'types' => [],
                        'paginated' => [],
                        'schemas' => [],
                        'dependencies' => [],
                    ];
                }

                $result = $this->resourceToInterface($resourceClass);
                if ($result) {
                    $domainContent[$domain]['interfaces'][] = $result['interface'];
                    $domainContent[$domain]['types'][] = $result['type'];
                    $domainContent[$domain]['paginated'][] = $result['paginated'];
                }
            }
        }

        // Process requests
        if (config('typescript-models.include_requests', true)) {
            foreach ($this->discoveredRequests as $requestClass) {
                $domain = $this->extractDomain($requestClass, $mode, 'requests');
                $allDomains[$domain] = true;

                if (!isset($domainContent[$domain])) {
                    $domainContent[$domain] = [
                        'interfaces' => [],
                        'types' => [],
                        'paginated' => [],
                        'schemas' => [],
                        'dependencies' => [],
                    ];
                }

                $result = $this->requestToInterface($requestClass);
                if ($result) {
                    $domainContent[$domain]['interfaces'][] = $result;
                }

                if (config('typescript-models.generate_yup_schemas', true)) {
                    $schema = $this->requestToYupSchema($requestClass);
                    if ($schema) {
                        $domainContent[$domain]['schemas'][] = $schema;
                    }
                }

                if (config('typescript-models.generate_zod_schemas', false)) {
                    $schema = $this->requestToZodSchema($requestClass);
                    if ($schema) {
                        $domainContent[$domain]['schemas'][] = $schema;
                    }
                }
            }
        }

        // Generate files
        $files = [];
        $timestamp = now()->toIso8601String();

        // Generate _shared.ts with pagination interfaces
        $files['_shared.ts'] = <<<TS
// =============================================================================
// Shared TypeScript interfaces
// Generated at: {$timestamp}
// Do not edit this file manually - it will be overwritten
// =============================================================================

{$this->generatePaginationInterfaces()}
TS;

        // Generate domain files
        foreach ($domainContent as $domain => $content) {
            $fileContent = <<<TS
// =============================================================================
// TypeScript interfaces for: {$domain}
// Generated at: {$timestamp}
// Do not edit this file manually - it will be overwritten
// =============================================================================

import type { PaginatedResponse } from './_shared';

TS;

            if (!empty($content['interfaces'])) {
                $fileContent .= implode("\n\n", $content['interfaces']) . "\n";
            }

            if (!empty($content['types'])) {
                $fileContent .= "\n// Array Types\n" . implode("\n", $content['types']) . "\n";
            }

            if (!empty($content['paginated'])) {
                $fileContent .= "\n// Paginated Types\n" . implode("\n", $content['paginated']) . "\n";
            }

            if (!empty($content['schemas'])) {
                $hasYup = config('typescript-models.generate_yup_schemas', true);
                $hasZod = config('typescript-models.generate_zod_schemas', false);

                $fileContent .= "\n// Validation Schemas\n";
                if ($hasYup) {
                    $fileContent .= "// Usage: import * as yup from 'yup';\n";
                }
                if ($hasZod) {
                    $fileContent .= "// Usage: import { z } from 'zod';\n";
                }
                $fileContent .= implode("\n\n", $content['schemas']) . "\n";
            }

            $files["{$domain}.ts"] = $fileContent;
        }

        // Generate index.ts with re-exports
        $exports = ["export * from './_shared';"];
        foreach (array_keys($domainContent) as $domain) {
            $exports[] = "export * from './{$domain}';";
        }
        sort($exports);

        $files['index.ts'] = <<<TS
// =============================================================================
// TypeScript interfaces index
// Generated at: {$timestamp}
// Do not edit this file manually - it will be overwritten
// =============================================================================

{$this->joinLines($exports)}
TS;

        return $files;
    }

    /**
     * Extract the domain name from a fully qualified class name.
     */
    protected function extractDomain(string $class, string $mode, string $type): string
    {
        $parts = explode('\\', $class);

        if ($mode === 'subdirectory') {
            // Find first segment after base namespace
            $baseCounts = [
                'models' => 2,     // App\Models\...
                'resources' => 3, // App\Http\Resources\...
                'requests' => 3,  // App\Http\Requests\...
            ];

            $baseCount = $baseCounts[$type] ?? 2;

            // If there's a subdirectory, use it; otherwise use 'default'
            if (count($parts) > $baseCount + 1) {
                return Str::snake($parts[$baseCount]);
            }

            return 'default';
        }

        if ($mode === 'class') {
            // Group by class name prefix (e.g., UserResource -> user)
            $className = class_basename($class);

            // Remove common suffixes
            $className = preg_replace('/(Resource|Request|Model)$/', '', $className);

            // Extract the main entity name (first word in PascalCase)
            if (preg_match('/^([A-Z][a-z]+)/', $className, $matches)) {
                return Str::snake($matches[1]);
            }

            return Str::snake($className);
        }

        return 'default';
    }

    /**
     * Helper to join lines with newlines.
     */
    protected function joinLines(array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * Generate pagination-related TypeScript interfaces.
     */
    protected function generatePaginationInterfaces(): string
    {
        return <<<'TS'
// Pagination Interfaces
export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  first_page_url: string;
  from: number | null;
  last_page: number;
  last_page_url: string;
  links: PaginationLink[];
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number | null;
  total: number;
}

TS;
    }

    /**
     * Generate the file header with timestamp and info.
     */
    protected function generateHeader(): string
    {
        $timestamp = now()->toIso8601String();

        return <<<TS
// =============================================================================
// Auto-generated TypeScript interfaces from Laravel Models
// Generated at: {$timestamp}
// Do not edit this file manually - it will be overwritten
// =============================================================================

TS;
    }

    /**
     * Discover all Eloquent models in the configured paths.
     */
    protected function discoverModels(): array
    {
        $paths = config('typescript-models.models_paths', [app_path('Models')]);
        $excludeModels = config('typescript-models.exclude_models', []);
        $models = [];

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $class = $this->getClassFromFile($file->getPathname());

                if (!$class) {
                    continue;
                }

                if (!class_exists($class)) {
                    continue;
                }

                if (!is_subclass_of($class, Model::class)) {
                    continue;
                }

                if (in_array($class, $excludeModels)) {
                    continue;
                }

                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * Discover all API Resources in the configured paths.
     */
    protected function discoverResources(): array
    {
        $paths = config('typescript-models.resources_paths', [app_path('Http/Resources')]);
        $excludeResources = config('typescript-models.exclude_resources', []);
        $resources = [];

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $class = $this->getClassFromFile($file->getPathname());

                if (!$class) {
                    continue;
                }

                if (!class_exists($class)) {
                    continue;
                }

                if (!is_subclass_of($class, JsonResource::class)) {
                    continue;
                }

                if (in_array($class, $excludeResources)) {
                    continue;
                }

                $resources[] = $class;
            }
        }

        sort($resources);

        return $resources;
    }

    /**
     * Discover all Form Requests in the configured paths.
     */
    protected function discoverRequests(): array
    {
        $paths = config('typescript-models.requests_paths', [app_path('Http/Requests')]);
        $excludeRequests = config('typescript-models.exclude_requests', []);
        $requests = [];

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $class = $this->getClassFromFile($file->getPathname());

                if (!$class) {
                    continue;
                }

                if (!class_exists($class)) {
                    continue;
                }

                if (!is_subclass_of($class, FormRequest::class)) {
                    continue;
                }

                if (in_array($class, $excludeRequests)) {
                    continue;
                }

                $requests[] = $class;
            }
        }

        sort($requests);

        return $requests;
    }

    /**
     * Extract the fully qualified class name from a PHP file.
     */
    protected function getClassFromFile(string $filePath): ?string
    {
        $contents = File::get($filePath);

        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($namespace && $class) {
            return $namespace . '\\' . $class;
        }

        return null;
    }

    /**
     * Convert a model class to a TypeScript interface.
     */
    protected function modelToInterface(string $modelClass): ?array
    {
        try {
            $model = new $modelClass;
            $reflection = new ReflectionClass($model);
            $interfaceName = $reflection->getShortName();
            $pluralName = Str::plural($interfaceName);

            $properties = $this->getModelProperties($model, $reflection);
            $relations = $this->getModelRelations($model, $reflection);

            // Detect and resolve conflicts between properties and relations
            $allProperties = $this->mergePropertiesWithConflictResolution($properties, $relations);

            if (empty($allProperties)) {
                return null;
            }

            // Sort by name
            usort($allProperties, fn($a, $b) => strcmp($a['name'], $b['name']));

            $propertiesString = implode("\n", array_map(
                fn($prop) => "  {$prop['name']}" . ($prop['nullable'] ? '?' : '') . ": {$prop['type']};",
                $allProperties
            ));

            return [
                'interface' => "export interface {$interfaceName} {\n{$propertiesString}\n}",
                'type' => "export type {$pluralName} = {$interfaceName}[];",
                'paginated' => "export type {$pluralName}Paginated = PaginatedResponse<{$interfaceName}>;",
            ];
        } catch (\Throwable $e) {
            return [
                'interface' => "// Error generating interface for {$modelClass}: {$e->getMessage()}",
                'type' => '',
                'paginated' => '',
            ];
        }
    }

    /**
     * Convert a resource class to a TypeScript interface.
     */
    protected function resourceToInterface(string $resourceClass): ?array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $interfaceName = $reflection->getShortName();
            $pluralName = Str::plural($interfaceName);

            // Try to get properties by executing toArray with a fake model
            $properties = $this->getResourcePropertiesByExecution($resourceClass, $reflection);

            // Fallback to static analysis if execution failed
            if (empty($properties)) {
                $properties = $this->getResourcePropertiesByStaticAnalysis($reflection);
            }

            if (empty($properties)) {
                return null;
            }

            // Apply intelligent type inference if enabled
            if (config('typescript-models.infer_resource_types', true)) {
                $properties = $this->enhanceResourcePropertyTypes($resourceClass, $reflection, $properties);
            }

            // Sort by name
            usort($properties, fn($a, $b) => strcmp($a['name'], $b['name']));

            $propertiesString = implode("\n", array_map(
                fn($prop) => "  {$prop['name']}" . ($prop['nullable'] ? '?' : '') . ": {$prop['type']};",
                $properties
            ));

            return [
                'interface' => "export interface {$interfaceName} {\n{$propertiesString}\n}",
                'type' => "export type {$pluralName} = {$interfaceName}[];",
                'paginated' => "export type {$pluralName}Paginated = PaginatedResponse<{$interfaceName}>;",
            ];
        } catch (\Throwable $e) {
            return [
                'interface' => "// Error generating interface for {$resourceClass}: {$e->getMessage()}",
                'type' => '',
                'paginated' => '',
            ];
        }
    }

    /**
     * Enhance resource property types using multiple inference strategies.
     */
    protected function enhanceResourcePropertyTypes(string $resourceClass, ReflectionClass $reflection, array $properties): array
    {
        // Strategy 1: Parse PHPDoc @property annotations
        $docTypes = $this->parseResourceDocBlock($reflection);

        // Strategy 2: Enhanced static analysis of toArray() method
        $staticTypes = $this->analyzeToArrayMethodForTypes($reflection);

        // Strategy 3: Infer from underlying Model
        $modelTypes = $this->inferTypesFromUnderlyingModel($resourceClass);

        // Apply type overrides (priority: PHPDoc > Static > Model > NamePattern > Fallback)
        $fallbackType = config('typescript-models.unknown_type_fallback', 'unknown');

        foreach ($properties as &$prop) {
            $name = $prop['name'];
            $currentType = $prop['type'];

            // Skip if already has a good type
            if ($currentType !== 'any' && $currentType !== 'unknown') {
                continue;
            }

            // Try PHPDoc first (highest priority)
            if (isset($docTypes[$name])) {
                $prop['type'] = $docTypes[$name];
                continue;
            }

            // Try static analysis
            if (isset($staticTypes[$name])) {
                $prop['type'] = $staticTypes[$name];
                continue;
            }

            // Try model types
            if (isset($modelTypes[$name])) {
                $prop['type'] = $modelTypes[$name];
                continue;
            }

            // Try name-based inference
            $inferredType = $this->inferTypeFromPropertyName($name);
            if ($inferredType !== null) {
                $prop['type'] = $inferredType;
                continue;
            }

            // Use fallback type
            $prop['type'] = $fallbackType;
        }

        return $properties;
    }

    /**
     * Parse PHPDoc @property and @property-read annotations from a Resource class.
     */
    protected function parseResourceDocBlock(ReflectionClass $reflection): array
    {
        $types = [];
        $docComment = $reflection->getDocComment();

        if (!$docComment) {
            return $types;
        }

        // Match @property and @property-read annotations
        // Pattern: @property(-read)? Type $name
        preg_match_all(
            '/@property(?:-read)?\s+([^\s]+)\s+\$([a-zA-Z_][a-zA-Z0-9_]*)/',
            $docComment,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $phpType = $match[1];
            $name = $match[2];
            $types[$name] = $this->phpTypeToTypeScript($phpType);
        }

        return $types;
    }

    /**
     * Enhanced static analysis of toArray() method to detect types from code patterns.
     */
    protected function analyzeToArrayMethodForTypes(ReflectionClass $reflection): array
    {
        $types = [];

        if (!$reflection->hasMethod('toArray')) {
            return $types;
        }

        try {
            $method = $reflection->getMethod('toArray');
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (!$filename || !$startLine || !$endLine) {
                return $types;
            }

            $lines = file($filename);
            $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // Pattern 1: Detect Resource::collection() - returns array of Resources
            // 'posts' => PostResource::collection($this->posts)
            preg_match_all(
                "/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>\s*([A-Z][a-zA-Z0-9_]*)::collection\s*\(/",
                $methodBody,
                $collectionMatches,
                PREG_SET_ORDER
            );

            foreach ($collectionMatches as $match) {
                $propName = $match[1];
                $resourceName = $match[2];
                $types[$propName] = "{$resourceName}[]";
            }

            // Pattern 2: Detect new Resource() - returns single Resource
            // 'user' => new UserResource($this->user)
            preg_match_all(
                "/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>\s*new\s+([A-Z][a-zA-Z0-9_]*Resource)\s*\(/",
                $methodBody,
                $newResourceMatches,
                PREG_SET_ORDER
            );

            foreach ($newResourceMatches as $match) {
                $propName = $match[1];
                $resourceName = $match[2];
                $types[$propName] = $resourceName;
            }

            // Pattern 3: Detect type casts - (bool), (int), (float), (string), (array)
            // 'is_active' => (bool) $this->active
            preg_match_all(
                "/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>\s*\((bool|int|integer|float|double|string|array)\)\s*/",
                $methodBody,
                $castMatches,
                PREG_SET_ORDER
            );

            foreach ($castMatches as $match) {
                $propName = $match[1];
                $cast = strtolower($match[2]);
                $types[$propName] = $this->phpCastToTypeScript($cast);
            }

            // Pattern 4: Detect ->format() calls (typically dates returning strings)
            // 'created_at' => $this->created_at->format('Y-m-d')
            preg_match_all(
                "/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>\s*\\\$this->[a-zA-Z_]+->format\s*\(/",
                $methodBody,
                $formatMatches,
                PREG_SET_ORDER
            );

            foreach ($formatMatches as $match) {
                $propName = $match[1];
                $types[$propName] = 'string';
            }

            // Pattern 5: Detect literal values
            // 'type' => 'user' or 'count' => 0 or 'active' => true
            preg_match_all(
                "/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>\s*('[^']*'|\"[^\"]*\"|true|false|\d+(?:\.\d+)?)\s*[,\n\]]/",
                $methodBody,
                $literalMatches,
                PREG_SET_ORDER
            );

            foreach ($literalMatches as $match) {
                $propName = $match[1];
                $literal = $match[2];

                if ($literal === 'true' || $literal === 'false') {
                    $types[$propName] = 'boolean';
                } elseif (is_numeric(trim($literal, '\'"'))) {
                    $types[$propName] = 'number';
                } elseif (preg_match('/^[\'"].*[\'"]$/', $literal)) {
                    $types[$propName] = 'string';
                }
            }

            // Pattern 6: Detect when() and whenLoaded() with Resources
            // 'profile' => $this->when($condition, new ProfileResource($this->profile))
            preg_match_all(
                "/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>\s*\\\$this->(?:when|whenLoaded)\s*\([^,]+,\s*(?:fn\s*\(\)\s*=>\s*)?new\s+([A-Z][a-zA-Z0-9_]*Resource)/",
                $methodBody,
                $whenMatches,
                PREG_SET_ORDER
            );

            foreach ($whenMatches as $match) {
                $propName = $match[1];
                $resourceName = $match[2];
                $types[$propName] = $resourceName;
            }

        } catch (\Throwable $e) {
            // Silently fail on analysis errors
        }

        return $types;
    }

    /**
     * Infer types from the underlying Model's casts and properties.
     */
    protected function inferTypesFromUnderlyingModel(string $resourceClass): array
    {
        $types = [];

        // Try to determine the Model class from Resource name
        // UserResource -> User, PostSummaryResource -> Post
        $resourceName = class_basename($resourceClass);
        $modelName = preg_replace('/(Summary|Detail|Full|Basic|Simple)?Resource$/', '', $resourceName);

        // Try common model namespaces
        $possibleModelClasses = [
            "App\\Models\\{$modelName}",
            "App\\{$modelName}",
        ];

        $modelClass = null;
        foreach ($possibleModelClasses as $possibleClass) {
            if (class_exists($possibleClass) && is_subclass_of($possibleClass, Model::class)) {
                $modelClass = $possibleClass;
                break;
            }
        }

        if (!$modelClass) {
            return $types;
        }

        try {
            $model = new $modelClass();

            // Get casts from model
            $casts = $model->getCasts();
            foreach ($casts as $property => $cast) {
                $types[$property] = $this->castToTypeScript($cast);
            }

            // Get common ID fields
            $types['id'] = 'number';
            if (method_exists($model, 'getKeyType') && $model->getKeyType() === 'string') {
                $types['id'] = 'string';
            }

        } catch (\Throwable $e) {
            // Silently fail
        }

        return $types;
    }

    /**
     * Infer TypeScript type from a property name pattern.
     */
    protected function inferTypeFromPropertyName(string $name): ?string
    {
        // ID fields
        if ($name === 'id' || Str::endsWith($name, '_id')) {
            return 'number';
        }

        // Timestamp/date fields
        if (Str::endsWith($name, ['_at', '_date', '_time', '_on'])) {
            return 'string';
        }

        // Count fields
        if (Str::endsWith($name, '_count') || Str::startsWith($name, 'count_')) {
            return 'number';
        }

        // Boolean fields
        if (Str::startsWith($name, ['is_', 'has_', 'can_', 'should_', 'was_', 'will_', 'did_'])) {
            return 'boolean';
        }

        // URL fields
        if (Str::endsWith($name, '_url') || $name === 'url') {
            return 'string';
        }

        // Email fields
        if (Str::endsWith($name, '_email') || $name === 'email') {
            return 'string';
        }

        // Name fields
        if (Str::endsWith($name, '_name') || $name === 'name' || $name === 'title' || $name === 'label') {
            return 'string';
        }

        // Description/content fields
        if (in_array($name, ['description', 'content', 'body', 'text', 'message', 'bio', 'summary'])) {
            return 'string';
        }

        // Price/amount fields
        if (Str::endsWith($name, ['_price', '_amount', '_total', '_balance', '_cost'])) {
            return 'number';
        }

        // Percentage fields
        if (Str::endsWith($name, ['_percent', '_percentage', '_rate'])) {
            return 'number';
        }

        return null;
    }

    /**
     * Convert a PHP type hint to TypeScript type.
     */
    protected function phpTypeToTypeScript(string $phpType): string
    {
        // Remove nullable indicator
        $isNullable = Str::startsWith($phpType, '?');
        $phpType = ltrim($phpType, '?');

        // Handle array types
        if (Str::endsWith($phpType, '[]')) {
            $innerType = substr($phpType, 0, -2);
            $tsInnerType = $this->phpTypeToTypeScript($innerType);
            return "{$tsInnerType}[]";
        }

        // Handle common PHP types
        $typeMap = [
            'int' => 'number',
            'integer' => 'number',
            'float' => 'number',
            'double' => 'number',
            'bool' => 'boolean',
            'boolean' => 'boolean',
            'string' => 'string',
            'array' => 'unknown[]',
            'object' => 'Record<string, unknown>',
            'mixed' => 'unknown',
            'null' => 'null',
            'void' => 'void',
            'DateTime' => 'string',
            '\\DateTime' => 'string',
            'DateTimeInterface' => 'string',
            '\\DateTimeInterface' => 'string',
            'Carbon' => 'string',
            'Carbon\\Carbon' => 'string',
            '\\Carbon\\Carbon' => 'string',
            'Illuminate\\Support\\Carbon' => 'string',
            '\\Illuminate\\Support\\Carbon' => 'string',
            'Collection' => 'unknown[]',
            'Illuminate\\Support\\Collection' => 'unknown[]',
        ];

        if (isset($typeMap[$phpType])) {
            return $typeMap[$phpType];
        }

        // Check if it's a Resource class
        if (Str::endsWith($phpType, 'Resource')) {
            // Strip namespace if present
            $parts = explode('\\', $phpType);
            return end($parts);
        }

        // Default for unknown types
        return config('typescript-models.unknown_type_fallback', 'unknown');
    }

    /**
     * Convert a PHP cast type to TypeScript type.
     */
    protected function phpCastToTypeScript(string $cast): string
    {
        return match ($cast) {
            'bool', 'boolean' => 'boolean',
            'int', 'integer' => 'number',
            'float', 'double', 'real' => 'number',
            'string' => 'string',
            'array' => 'unknown[]',
            default => 'unknown',
        };
    }

    /**
     * Get resource properties by executing toArray with a fake model.
     */
    protected function getResourcePropertiesByExecution(string $resourceClass, ReflectionClass $reflection): array
    {
        try {
            // Create an anonymous class that extends Model and returns null for any attribute
            $fakeModel = new class extends Model {
                protected $guarded = [];

                public function getAttribute($key)
                {
                    return null;
                }

                public function relationLoaded($key): bool
                {
                    return false;
                }

                public function getRelation($key)
                {
                    return null;
                }
            };

            $resource = new $resourceClass($fakeModel);
            $fakeRequest = Request::create('/', 'GET');

            $array = $resource->toArray($fakeRequest);

            if (!is_array($array)) {
                return [];
            }

            $properties = [];
            foreach ($array as $key => $value) {
                // Skip numeric keys (from array_merge or similar)
                if (is_numeric($key)) {
                    continue;
                }

                $properties[] = [
                    'name' => $key,
                    'type' => $this->inferTypeFromValue($value),
                    'nullable' => true,
                ];
            }

            return $properties;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get resource properties by static analysis of toArray method.
     */
    protected function getResourcePropertiesByStaticAnalysis(ReflectionClass $reflection): array
    {
        try {
            if (!$reflection->hasMethod('toArray')) {
                return [];
            }

            $method = $reflection->getMethod('toArray');
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (!$filename || !$startLine || !$endLine) {
                return [];
            }

            $lines = file($filename);
            $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // Find array keys in patterns like 'key' => or "key" =>
            preg_match_all("/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>/", $methodBody, $matches);

            $properties = [];
            $seenKeys = [];

            foreach ($matches[1] as $key) {
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;

                $properties[] = [
                    'name' => $key,
                    'type' => config('typescript-models.unknown_type_fallback', 'unknown'),
                    'nullable' => true,
                ];
            }

            return $properties;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Infer TypeScript type from a PHP value.
     */
    protected function inferTypeFromValue(mixed $value): string
    {
        $fallback = config('typescript-models.unknown_type_fallback', 'unknown');

        if (is_null($value)) {
            return $fallback;
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value)) {
            return 'number';
        }

        if (is_float($value)) {
            return 'number';
        }

        if (is_array($value)) {
            if (empty($value)) {
                return "{$fallback}[]";
            }

            // Check if it's an associative array (object) or sequential array
            if (array_keys($value) !== range(0, count($value) - 1)) {
                return "Record<string, {$fallback}>";
            }

            // Try to infer type from first element
            $firstValue = reset($value);
            $elementType = $this->inferTypeFromValue($firstValue);
            return "{$elementType}[]";
        }

        if (is_object($value)) {
            // Check if it's a JsonResource
            if ($value instanceof JsonResource) {
                $resourceName = class_basename($value);
                if (in_array(get_class($value), $this->discoveredResources)) {
                    return $resourceName;
                }
            }

            return "Record<string, {$fallback}>";
        }

        return 'string';
    }

    /**
     * Convert a Form Request class to a TypeScript interface.
     */
    protected function requestToInterface(string $requestClass): ?string
    {
        try {
            $reflection = new ReflectionClass($requestClass);
            $interfaceName = $this->requestNames[$requestClass] ?? $reflection->getShortName();

            // Try to get rules by instantiating and calling rules()
            $properties = $this->getRequestPropertiesByExecution($requestClass);

            // Fallback to static analysis if execution failed
            if (empty($properties)) {
                $properties = $this->getRequestPropertiesByStaticAnalysis($reflection);
            }

            if (empty($properties)) {
                return null;
            }

            // Sort by name
            usort($properties, fn($a, $b) => strcmp($a['name'], $b['name']));

            $propertiesString = implode("\n", array_map(
                fn($prop) => "  {$prop['name']}" . ($prop['nullable'] ? '?' : '') . ": {$prop['type']};",
                $properties
            ));

            return "export interface {$interfaceName} {\n{$propertiesString}\n}";
        } catch (\Throwable $e) {
            return "// Error generating interface for {$requestClass}: {$e->getMessage()}";
        }
    }

    /**
     * Get request properties by executing rules() method.
     */
    protected function getRequestPropertiesByExecution(string $requestClass): array
    {
        try {
            // Create a fake request to instantiate the FormRequest
            $fakeRequest = Request::create('/', 'POST');
            $formRequest = new $requestClass();

            // Set the request data to avoid issues
            $formRequest->setContainer(app());

            // Call rules() method
            if (!method_exists($formRequest, 'rules')) {
                return [];
            }

            $rules = $formRequest->rules();

            if (!is_array($rules)) {
                return [];
            }

            return $this->parseValidationRules($rules);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get request properties by static analysis of rules() method.
     */
    protected function getRequestPropertiesByStaticAnalysis(ReflectionClass $reflection): array
    {
        try {
            if (!$reflection->hasMethod('rules')) {
                return [];
            }

            $method = $reflection->getMethod('rules');
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (!$filename || !$startLine || !$endLine) {
                return [];
            }

            $lines = file($filename);
            $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // Find array keys in patterns like 'key' => or "key" =>
            preg_match_all("/['\"]([a-zA-Z_][a-zA-Z0-9_.*]*)['\"]\s*=>/", $methodBody, $matches);

            $properties = [];
            $seenKeys = [];

            foreach ($matches[1] as $key) {
                // Handle nested rules like 'items.*' or 'items.*.name'
                $baseKey = explode('.', $key)[0];

                if (isset($seenKeys[$baseKey])) {
                    continue;
                }
                $seenKeys[$baseKey] = true;

                // Try to detect if it's an array field
                $isArray = str_contains($key, '.*') || str_contains($key, '.*.');

                $properties[] = [
                    'name' => $baseKey,
                    'type' => $isArray ? 'any[]' : 'any',
                    'nullable' => true,
                ];
            }

            return $properties;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Parse Laravel validation rules and convert to TypeScript properties.
     */
    protected function parseValidationRules(array $rules): array
    {
        $properties = [];
        $seenKeys = [];

        foreach ($rules as $field => $fieldRules) {
            // Handle nested rules like 'items.*' or 'items.*.name'
            $baseField = explode('.', $field)[0];

            if (isset($seenKeys[$baseField])) {
                continue;
            }
            $seenKeys[$baseField] = true;

            // Normalize rules to array
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            // Convert Rule objects to strings where possible
            $rulesArray = [];
            foreach ($fieldRules as $rule) {
                if (is_string($rule)) {
                    $rulesArray[] = strtolower($rule);
                } elseif (is_object($rule)) {
                    // Try to extract values from Rule::in() objects
                    $ruleString = $this->extractRuleObjectString($rule);
                    $rulesArray[] = $ruleString;
                }
            }

            // Determine if field is required or nullable
            $isRequired = in_array('required', $rulesArray);
            $isNullable = in_array('nullable', $rulesArray) || in_array('sometimes', $rulesArray);

            // If not explicitly required, treat as optional
            $isOptional = !$isRequired || $isNullable;

            // Determine TypeScript type from rules
            $type = $this->validationRuleToTypeScript($rulesArray, $field);

            $properties[] = [
                'name' => $baseField,
                'type' => $type,
                'nullable' => $isOptional,
            ];
        }

        return $properties;
    }

    /**
     * Extract rule string from Rule objects (e.g., Rule::in()).
     */
    protected function extractRuleObjectString(object $rule): string
    {
        $className = class_basename($rule);

        // Handle Illuminate\Validation\Rules\In
        if ($className === 'In') {
            try {
                // Use reflection to get the values property
                $reflection = new ReflectionClass($rule);
                if ($reflection->hasProperty('values')) {
                    $prop = $reflection->getProperty('values');
                    $prop->setAccessible(true);
                    $values = $prop->getValue($rule);

                    if (is_array($values)) {
                        return 'in:' . implode(',', $values);
                    }
                }
            } catch (\Throwable) {
                // Fallback
            }
        }

        // Handle Illuminate\Validation\Rules\Enum
        if ($className === 'Enum') {
            try {
                $reflection = new ReflectionClass($rule);
                if ($reflection->hasProperty('type')) {
                    $prop = $reflection->getProperty('type');
                    $prop->setAccessible(true);
                    $enumClass = $prop->getValue($rule);

                    // Get enum cases
                    if (enum_exists($enumClass)) {
                        $cases = array_map(fn($case) => $case->value ?? $case->name, $enumClass::cases());
                        return 'in:' . implode(',', $cases);
                    }
                }
            } catch (\Throwable) {
                // Fallback
            }
        }

        return strtolower($className);
    }

    /**
     * Convert Laravel validation rules to TypeScript type.
     */
    protected function validationRuleToTypeScript(array $rules, string $field): string
    {
        // Check for array type first (items.* pattern or 'array' rule)
        if (str_contains($field, '.*') || in_array('array', $rules)) {
            return 'any[]';
        }

        // Check for specific types
        foreach ($rules as $rule) {
            // Handle rules with parameters like 'max:255'
            $ruleParts = explode(':', $rule, 2);
            $ruleName = $ruleParts[0];
            $ruleParams = $ruleParts[1] ?? '';

            switch ($ruleName) {
                case 'in':
                case 'in_array':
                    // Generate union literal type: 'value1' | 'value2' | 'value3'
                    if ($ruleParams) {
                        $values = array_map('trim', explode(',', $ruleParams));
                        $unionTypes = array_map(function ($value) {
                            // Check if it's a number
                            if (is_numeric($value)) {
                                return $value;
                            }
                            // It's a string, wrap in quotes
                            return "'" . addslashes($value) . "'";
                        }, $values);
                        return implode(' | ', $unionTypes);
                    }
                    return 'string';

                case 'integer':
                case 'numeric':
                case 'digits':
                case 'digits_between':
                    return 'number';

                case 'boolean':
                case 'bool':
                case 'accepted':
                case 'declined':
                    return 'boolean';

                case 'array':
                    return 'any[]';

                case 'file':
                case 'image':
                case 'mimes':
                case 'mimetypes':
                    return 'File';

                case 'date':
                case 'date_format':
                case 'before':
                case 'after':
                case 'before_or_equal':
                case 'after_or_equal':
                    return 'string'; // Dates are usually sent as strings

                case 'json':
                    return 'Record<string, any>';

                case 'string':
                case 'email':
                case 'url':
                case 'uuid':
                case 'ip':
                case 'ipv4':
                case 'ipv6':
                case 'mac_address':
                case 'regex':
                case 'alpha':
                case 'alpha_dash':
                case 'alpha_num':
                    return 'string';
            }
        }

        // Default to string
        return 'string';
    }

    /**
     * Convert a Form Request class to a Yup validation schema.
     */
    protected function requestToYupSchema(string $requestClass): ?string
    {
        try {
            $reflection = new ReflectionClass($requestClass);
            $schemaName = ($this->requestNames[$requestClass] ?? $reflection->getShortName()) . 'Schema';

            // Try to get rules by instantiating and calling rules()
            $rules = $this->getRequestRules($requestClass);

            // Fallback to static analysis if execution failed
            if (empty($rules)) {
                return null;
            }

            $fields = $this->validationRulesToYup($rules);

            if (empty($fields)) {
                return null;
            }

            $fieldsString = implode(",\n", array_map(
                fn($field) => "  {$field['name']}: {$field['schema']}",
                $fields
            ));

            return "export const {$schemaName} = yup.object({\n{$fieldsString},\n});";
        } catch (\Throwable $e) {
            return "// Error generating Yup schema for {$requestClass}: {$e->getMessage()}";
        }
    }

    /**
     * Get validation rules from a Form Request class.
     */
    protected function getRequestRules(string $requestClass): array
    {
        try {
            $formRequest = new $requestClass();
            $formRequest->setContainer(app());

            if (!method_exists($formRequest, 'rules')) {
                return [];
            }

            $rules = $formRequest->rules();

            return is_array($rules) ? $rules : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Convert Laravel validation rules to Yup schema fields.
     */
    protected function validationRulesToYup(array $rules): array
    {
        $fields = [];
        $seenKeys = [];

        foreach ($rules as $field => $fieldRules) {
            // Handle nested rules like 'items.*' or 'items.*.name'
            $baseField = explode('.', $field)[0];

            if (isset($seenKeys[$baseField])) {
                continue;
            }
            $seenKeys[$baseField] = true;

            // Normalize rules to array
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            // Convert Rule objects to strings where possible
            $rulesArray = [];
            $rulesWithParams = [];

            foreach ($fieldRules as $rule) {
                if (is_string($rule)) {
                    $rulesArray[] = strtolower(explode(':', $rule)[0]);
                    $rulesWithParams[] = strtolower($rule);
                } elseif (is_object($rule)) {
                    $rulesArray[] = strtolower(class_basename($rule));
                    $rulesWithParams[] = strtolower(class_basename($rule));
                }
            }

            $schema = $this->buildYupSchema($rulesArray, $rulesWithParams, $field);

            $fields[] = [
                'name' => $baseField,
                'schema' => $schema,
            ];
        }

        return $fields;
    }

    /**
     * Build a Yup schema string from Laravel validation rules.
     */
    protected function buildYupSchema(array $rules, array $rulesWithParams, string $field): string
    {
        $schema = [];

        // Determine base type
        $baseType = $this->getYupBaseType($rules, $field);
        $schema[] = "yup.{$baseType}()";

        // Add validations based on rules
        foreach ($rulesWithParams as $rule) {
            $parts = explode(':', $rule);
            $ruleName = $parts[0];
            $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

            switch ($ruleName) {
                case 'required':
                    $schema[] = "required('This field is required')";
                    break;

                case 'nullable':
                    $schema[] = "nullable()";
                    break;

                case 'email':
                    $schema[] = "email('Invalid email address')";
                    break;

                case 'url':
                    $schema[] = "url('Invalid URL')";
                    break;

                case 'min':
                    if (!empty($params[0])) {
                        if (in_array('string', $rules)) {
                            $schema[] = "min({$params[0]}, 'Must be at least {$params[0]} characters')";
                        } else {
                            $schema[] = "min({$params[0]}, 'Must be at least {$params[0]}')";
                        }
                    }
                    break;

                case 'max':
                    if (!empty($params[0])) {
                        if (in_array('string', $rules)) {
                            $schema[] = "max({$params[0]}, 'Must be at most {$params[0]} characters')";
                        } else {
                            $schema[] = "max({$params[0]}, 'Must be at most {$params[0]}')";
                        }
                    }
                    break;

                case 'between':
                    if (count($params) >= 2) {
                        $schema[] = "min({$params[0]})";
                        $schema[] = "max({$params[1]})";
                    }
                    break;

                case 'size':
                    if (!empty($params[0])) {
                        if (in_array('string', $rules)) {
                            $schema[] = "length({$params[0]}, 'Must be exactly {$params[0]} characters')";
                        }
                    }
                    break;

                case 'confirmed':
                    $schema[] = "oneOf([yup.ref('{$field}_confirmation')], 'Must match confirmation')";
                    break;

                case 'regex':
                    if (!empty($params[0])) {
                        // Remove delimiters from regex
                        $pattern = trim($params[0], '/');
                        $schema[] = "matches(/{$pattern}/, 'Invalid format')";
                    }
                    break;

                case 'in':
                    if (!empty($params)) {
                        $values = implode("', '", $params);
                        $schema[] = "oneOf(['{$values}'], 'Invalid value')";
                    }
                    break;

                case 'uuid':
                    $schema[] = "uuid('Invalid UUID')";
                    break;

                case 'integer':
                    $schema[] = "integer('Must be an integer')";
                    break;

                case 'positive':
                    $schema[] = "positive('Must be positive')";
                    break;

                case 'negative':
                    $schema[] = "negative('Must be negative')";
                    break;
            }
        }

        // If not required and not nullable, make it optional
        if (!in_array('required', $rules) && !in_array('nullable', $rules)) {
            $schema[] = "optional()";
        }

        return implode('.', $schema);
    }

    /**
     * Determine the base Yup type from Laravel rules.
     */
    protected function getYupBaseType(array $rules, string $field): string
    {
        // Check for array type first
        if (str_contains($field, '.*') || in_array('array', $rules)) {
            return 'array';
        }

        // Check for specific types
        foreach ($rules as $rule) {
            switch ($rule) {
                case 'integer':
                case 'numeric':
                case 'digits':
                case 'digits_between':
                    return 'number';

                case 'boolean':
                case 'bool':
                case 'accepted':
                case 'declined':
                    return 'boolean';

                case 'array':
                    return 'array';

                case 'date':
                case 'date_format':
                    return 'date';

                case 'file':
                case 'image':
                case 'mimes':
                case 'mimetypes':
                    return 'mixed'; // Yup doesn't have a file type

                case 'string':
                case 'email':
                case 'url':
                case 'uuid':
                case 'ip':
                case 'regex':
                case 'alpha':
                case 'alpha_dash':
                case 'alpha_num':
                    return 'string';
            }
        }

        // Default to string
        return 'string';
    }

    /**
     * Convert a Form Request class to a Zod validation schema.
     */
    protected function requestToZodSchema(string $requestClass): ?string
    {
        try {
            $reflection = new ReflectionClass($requestClass);
            $schemaName = ($this->requestNames[$requestClass] ?? $reflection->getShortName()) . 'Schema';

            // Try to get rules by instantiating and calling rules()
            $rules = $this->getRequestRules($requestClass);

            // Fallback to static analysis if execution failed
            if (empty($rules)) {
                return null;
            }

            $fields = $this->validationRulesToZod($rules);

            if (empty($fields)) {
                return null;
            }

            $fieldsString = implode(",\n", array_map(
                fn($field) => "  {$field['name']}: {$field['schema']}",
                $fields
            ));

            return "export const {$schemaName} = z.object({\n{$fieldsString},\n});";
        } catch (\Throwable $e) {
            return "// Error generating Zod schema for {$requestClass}: {$e->getMessage()}";
        }
    }

    /**
     * Convert Laravel validation rules to Zod schema fields.
     */
    protected function validationRulesToZod(array $rules): array
    {
        $fields = [];
        $seenKeys = [];

        foreach ($rules as $field => $fieldRules) {
            // Handle nested rules like 'items.*' or 'items.*.name'
            $baseField = explode('.', $field)[0];

            if (isset($seenKeys[$baseField])) {
                continue;
            }
            $seenKeys[$baseField] = true;

            // Normalize rules to array
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            // Convert Rule objects to strings where possible
            $rulesArray = [];
            $rulesWithParams = [];

            foreach ($fieldRules as $rule) {
                if (is_string($rule)) {
                    $rulesArray[] = strtolower(explode(':', $rule)[0]);
                    $rulesWithParams[] = strtolower($rule);
                } elseif (is_object($rule)) {
                    $ruleString = $this->extractRuleObjectString($rule);
                    $rulesArray[] = strtolower(explode(':', $ruleString)[0]);
                    $rulesWithParams[] = strtolower($ruleString);
                }
            }

            $schema = $this->buildZodSchema($rulesArray, $rulesWithParams, $field);

            $fields[] = [
                'name' => $baseField,
                'schema' => $schema,
            ];
        }

        return $fields;
    }

    /**
     * Build a Zod schema string from Laravel validation rules.
     */
    protected function buildZodSchema(array $rules, array $rulesWithParams, string $field): string
    {
        $schema = [];

        // Determine base type
        $baseType = $this->getZodBaseType($rules, $field);
        $schema[] = "z.{$baseType}()";

        // Add validations based on rules
        foreach ($rulesWithParams as $rule) {
            $parts = explode(':', $rule);
            $ruleName = $parts[0];
            $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

            switch ($ruleName) {
                case 'email':
                    $schema[] = "email({ message: 'Invalid email address' })";
                    break;

                case 'url':
                    $schema[] = "url({ message: 'Invalid URL' })";
                    break;

                case 'min':
                    if (!empty($params[0])) {
                        if (in_array('string', $rules)) {
                            $schema[] = "min({$params[0]}, { message: 'Must be at least {$params[0]} characters' })";
                        } else {
                            $schema[] = "min({$params[0]}, { message: 'Must be at least {$params[0]}' })";
                        }
                    }
                    break;

                case 'max':
                    if (!empty($params[0])) {
                        if (in_array('string', $rules)) {
                            $schema[] = "max({$params[0]}, { message: 'Must be at most {$params[0]} characters' })";
                        } else {
                            $schema[] = "max({$params[0]}, { message: 'Must be at most {$params[0]}' })";
                        }
                    }
                    break;

                case 'between':
                    if (count($params) >= 2) {
                        $schema[] = "min({$params[0]})";
                        $schema[] = "max({$params[1]})";
                    }
                    break;

                case 'size':
                    if (!empty($params[0])) {
                        if (in_array('string', $rules)) {
                            $schema[] = "length({$params[0]}, { message: 'Must be exactly {$params[0]} characters' })";
                        }
                    }
                    break;

                case 'regex':
                    if (!empty($params[0])) {
                        // Remove delimiters from regex
                        $pattern = trim($params[0], '/');
                        $schema[] = "regex(/{$pattern}/, { message: 'Invalid format' })";
                    }
                    break;

                case 'in':
                    if (!empty($params)) {
                        // Check if all params are numeric
                        $allNumeric = array_reduce($params, fn($carry, $p) => $carry && is_numeric($p), true);
                        if ($allNumeric) {
                            $values = implode(', ', $params);
                            // Replace base type with enum for literals
                            $schema[0] = "z.union([" . implode(', ', array_map(fn($p) => "z.literal({$p})", $params)) . "])";
                        } else {
                            $schema[0] = "z.enum(['" . implode("', '", $params) . "'])";
                        }
                    }
                    break;

                case 'uuid':
                    $schema[] = "uuid({ message: 'Invalid UUID' })";
                    break;

                case 'integer':
                    $schema[] = "int({ message: 'Must be an integer' })";
                    break;

                case 'positive':
                    $schema[] = "positive({ message: 'Must be positive' })";
                    break;

                case 'negative':
                    $schema[] = "negative({ message: 'Must be negative' })";
                    break;
            }
        }

        // Handle nullable and optional
        $isRequired = in_array('required', $rules);
        $isNullable = in_array('nullable', $rules);

        if ($isNullable) {
            $schema[] = "nullable()";
        }

        if (!$isRequired) {
            $schema[] = "optional()";
        }

        return implode('.', $schema);
    }

    /**
     * Determine the base Zod type from Laravel rules.
     */
    protected function getZodBaseType(array $rules, string $field): string
    {
        // Check for array type first
        if (str_contains($field, '.*') || in_array('array', $rules)) {
            return 'array(z.any())';
        }

        // Check for specific types
        foreach ($rules as $rule) {
            switch ($rule) {
                case 'integer':
                case 'numeric':
                case 'digits':
                case 'digits_between':
                    return 'number';

                case 'boolean':
                case 'bool':
                case 'accepted':
                case 'declined':
                    return 'boolean';

                case 'array':
                    return 'array(z.any())';

                case 'date':
                case 'date_format':
                    return 'coerce.date';

                case 'file':
                case 'image':
                case 'mimes':
                case 'mimetypes':
                    return 'instanceof(File)';

                case 'string':
                case 'email':
                case 'url':
                case 'uuid':
                case 'ip':
                case 'regex':
                case 'alpha':
                case 'alpha_dash':
                case 'alpha_num':
                    return 'string';
            }
        }

        // Default to string
        return 'string';
    }

    /**
     * Get all properties for a model.
     */
    protected function getModelProperties(Model $model, ReflectionClass $reflection): array
    {
        $properties = [];
        $mode = config('typescript-models.properties_mode', 'fillable');

        switch ($mode) {
            case 'database':
                $properties = $this->getPropertiesFromDatabase($model);
                break;
            case 'fillable':
                $properties = $this->getPropertiesFromFillable($model);
                break;
            case 'both':
                $dbProps = $this->getPropertiesFromDatabase($model);
                $fillableProps = $this->getPropertiesFromFillable($model);
                $properties = array_merge($dbProps, $fillableProps);
                $properties = $this->uniqueProperties($properties);
                break;
        }

        if (config('typescript-models.include_accessors', false)) {
            $accessorProps = $this->getPropertiesFromAccessors($model, $reflection);
            $properties = array_merge($properties, $accessorProps);
            $properties = $this->uniqueProperties($properties);
        }

        return $properties;
    }

    /**
     * Get model relations with correct types.
     */
    protected function getModelRelations(Model $model, ReflectionClass $reflection): array
    {
        if (!config('typescript-models.include_relations', true)) {
            return [];
        }

        $relations = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Skip methods with required parameters
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Skip inherited methods from Model class
            if ($method->class === Model::class || $method->class === 'Illuminate\Database\Eloquent\Model') {
                continue;
            }

            // Skip common non-relation methods
            $skipMethods = [
                '__construct', '__call', '__callStatic', '__get', '__set',
                'boot', 'booted', 'booting', 'toArray', 'toJson', 'jsonSerialize',
                'getKey', 'getKeyName', 'getTable', 'getFillable', 'getHidden',
                'getCasts', 'getDates', 'getAttributes', 'getRelations',
                'newQuery', 'newModelQuery', 'newEloquentBuilder', 'newCollection',
                'newPivot', 'resolveRouteBinding', 'resolveSoftDeletableRouteBinding',
            ];

            if (in_array($method->getName(), $skipMethods)) {
                continue;
            }

            try {
                $relationInfo = $this->detectRelationType($method);

                if ($relationInfo) {
                    // Try to call the method to get the related model
                    $relation = $model->{$method->getName()}();
                    $relatedModel = get_class($relation->getRelated());
                    $relatedName = class_basename($relatedModel);

                    // Check if related model is in our discovered models
                    if (!in_array($relatedModel, $this->discoveredModels)) {
                        $relatedName = 'any';
                    }

                    $type = $relationInfo['isMany'] ? "{$relatedName}[]" : $relatedName;
                    $relationName = Str::snake($method->getName());

                    $relations[] = [
                        'name' => $relationName,
                        'type' => $type,
                        'nullable' => true,
                    ];
                }
            } catch (\Throwable) {
                // Skip methods that throw errors
                continue;
            }
        }

        return $relations;
    }

    /**
     * Detect relation type from method using multiple strategies.
     */
    protected function detectRelationType(ReflectionMethod $method): ?array
    {
        // Strategy 1: Check return type hint
        $returnType = $method->getReturnType();
        if ($returnType) {
            $typeName = $returnType->getName();
            $relationInfo = $this->getRelationInfo($typeName);
            if ($relationInfo) {
                return $relationInfo;
            }
        }

        // Strategy 2: Check PHPDoc @return
        $docComment = $method->getDocComment();
        if ($docComment) {
            $relationInfo = $this->parseDocBlockForRelation($docComment);
            if ($relationInfo) {
                return $relationInfo;
            }
        }

        // Strategy 3: Analyze method body for relation calls
        $relationInfo = $this->analyzeMethodBody($method);
        if ($relationInfo) {
            return $relationInfo;
        }

        return null;
    }

    /**
     * Parse PHPDoc block for relation type.
     */
    protected function parseDocBlockForRelation(string $docComment): ?array
    {
        $relationPatterns = [
            // Many relations
            'HasMany' => true,
            'BelongsToMany' => true,
            'MorphMany' => true,
            'MorphToMany' => true,
            'HasManyThrough' => true,
            // Single relations
            'HasOne' => false,
            'BelongsTo' => false,
            'MorphOne' => false,
            'MorphTo' => false,
            'HasOneThrough' => false,
        ];

        foreach ($relationPatterns as $relation => $isMany) {
            if (preg_match('/@return\s+.*' . $relation . '/i', $docComment)) {
                return ['isMany' => $isMany];
            }
        }

        return null;
    }

    /**
     * Analyze method body to detect relation type.
     */
    protected function analyzeMethodBody(ReflectionMethod $method): ?array
    {
        try {
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (!$filename || !$startLine || !$endLine) {
                return null;
            }

            $lines = file($filename);
            $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // Relation method patterns
            $manyRelations = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'hasManyThrough'];
            $singleRelations = ['hasOne', 'belongsTo', 'morphOne', 'morphTo', 'hasOneThrough'];

            foreach ($manyRelations as $relation) {
                if (preg_match('/\$this\s*->\s*' . $relation . '\s*\(/i', $methodBody)) {
                    return ['isMany' => true];
                }
            }

            foreach ($singleRelations as $relation) {
                if (preg_match('/\$this\s*->\s*' . $relation . '\s*\(/i', $methodBody)) {
                    return ['isMany' => false];
                }
            }
        } catch (\Throwable) {
            // Ignore errors
        }

        return null;
    }

    /**
     * Get relation info from return type.
     */
    protected function getRelationInfo(string $typeName): ?array
    {
        $manyRelations = [
            HasMany::class,
            BelongsToMany::class,
            MorphMany::class,
            MorphToMany::class,
            HasManyThrough::class,
        ];

        $singleRelations = [
            HasOne::class,
            BelongsTo::class,
            MorphOne::class,
            MorphTo::class,
            HasOneThrough::class,
        ];

        if (in_array($typeName, $manyRelations)) {
            return ['isMany' => true];
        }

        if (in_array($typeName, $singleRelations)) {
            return ['isMany' => false];
        }

        return null;
    }

    /**
     * Get properties from database schema.
     */
    protected function getPropertiesFromDatabase(Model $model): array
    {
        $properties = [];
        $table = $model->getTable();
        $connection = $model->getConnectionName();

        try {
            $columns = Schema::connection($connection)->getColumns($table);
            $casts = $model->getCasts();

            foreach ($columns as $column) {
                $name = $column['name'];
                $castType = $casts[$name] ?? null;
                $dbType = $column['type_name'];

                $properties[] = [
                    'name' => $name,
                    'type' => $this->resolveType($castType, $dbType),
                    'nullable' => $column['nullable'] ?? true,
                ];
            }
        } catch (\Throwable $e) {
            // Database not available, skip
        }

        return $properties;
    }

    /**
     * Get properties from fillable array.
     */
    protected function getPropertiesFromFillable(Model $model): array
    {
        $properties = [];
        $fillable = $model->getFillable();
        $casts = $model->getCasts();

        foreach ($fillable as $field) {
            $castType = $casts[$field] ?? 'string';

            $properties[] = [
                'name' => $field,
                'type' => $this->resolveType($castType, null),
                'nullable' => true,
            ];
        }

        // Add primary key
        $primaryKey = $model->getKeyName();
        if ($primaryKey && !in_array($primaryKey, $fillable)) {
            $properties[] = [
                'name' => $primaryKey,
                'type' => $model->getKeyType() === 'int' ? 'number' : 'string',
                'nullable' => false,
            ];
        }

        // Add timestamps if enabled
        if ($model->usesTimestamps()) {
            $createdAt = $model->getCreatedAtColumn();
            $updatedAt = $model->getUpdatedAtColumn();

            if ($createdAt) {
                $properties[] = [
                    'name' => $createdAt,
                    'type' => 'Date',
                    'nullable' => true,
                ];
            }

            if ($updatedAt) {
                $properties[] = [
                    'name' => $updatedAt,
                    'type' => 'Date',
                    'nullable' => true,
                ];
            }
        }

        return $properties;
    }

    /**
     * Get properties from model accessors.
     */
    protected function getPropertiesFromAccessors(Model $model, ReflectionClass $reflection): array
    {
        $properties = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Laravel 9+ style: Attribute casts
            if ($method->getReturnType()?->getName() === 'Illuminate\Database\Eloquent\Casts\Attribute') {
                $name = Str::snake($method->getName());
                $properties[] = [
                    'name' => $name,
                    'type' => 'any',
                    'nullable' => true,
                ];
            }

            // Legacy style: getXxxAttribute
            if (preg_match('/^get(.+)Attribute$/', $method->getName(), $matches)) {
                $name = Str::snake($matches[1]);
                $returnType = $method->getReturnType();
                $type = $returnType ? $this->resolveType($returnType->getName(), null) : 'any';

                $properties[] = [
                    'name' => $name,
                    'type' => $type,
                    'nullable' => $returnType?->allowsNull() ?? true,
                ];
            }
        }

        return $properties;
    }

    /**
     * Resolve the TypeScript type from cast or database type.
     */
    protected function resolveType(?string $castType, ?string $dbType): string
    {
        // Priority: cast type > database type
        if ($castType) {
            return $this->castToTypeScript($castType);
        }

        if ($dbType) {
            return $this->databaseToTypeScript($dbType);
        }

        return 'any';
    }

    /**
     * Convert Laravel cast to TypeScript type.
     */
    protected function castToTypeScript(string $cast): string
    {
        $cast = strtolower($cast);

        // Handle array casts like 'array:App\Enums\Status'
        if (str_contains($cast, ':')) {
            $cast = explode(':', $cast)[0];
        }

        return match ($cast) {
            'int', 'integer' => 'number',
            'real', 'float', 'double', 'decimal' => 'number',
            'bool', 'boolean' => 'boolean',
            'array', 'json', 'collection' => 'any[]',
            'object' => 'Record<string, any>',
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp' => 'Date',
            'encrypted', 'hashed', 'string' => 'string',
            default => 'any',
        };
    }

    /**
     * Convert database column type to TypeScript type.
     */
    protected function databaseToTypeScript(string $dbType): string
    {
        $dbType = strtolower($dbType);

        return match (true) {
            str_contains($dbType, 'int') => 'number',
            str_contains($dbType, 'serial') => 'number',
            str_contains($dbType, 'float'),
            str_contains($dbType, 'double'),
            str_contains($dbType, 'decimal'),
            str_contains($dbType, 'numeric'),
            str_contains($dbType, 'money') => 'number',
            str_contains($dbType, 'bool') => 'boolean',
            str_contains($dbType, 'json') => 'any[]',
            str_contains($dbType, 'date'),
            str_contains($dbType, 'time') => 'Date',
            default => 'string',
        };
    }

    /**
     * Remove duplicate properties, keeping the first occurrence.
     */
    protected function uniqueProperties(array $properties): array
    {
        $seen = [];
        $unique = [];

        foreach ($properties as $prop) {
            if (!isset($seen[$prop['name']])) {
                $seen[$prop['name']] = true;
                $unique[] = $prop;
            }
        }

        return $unique;
    }

    /**
     * Merge properties and relations with conflict resolution.
     *
     * When a property (column) has the same name as a relation, this method:
     * - Renames numeric properties to {name}_count (e.g., clicks -> clicks_count)
     * - Keeps the relation with the original name
     */
    protected function mergePropertiesWithConflictResolution(array $properties, array $relations): array
    {
        $relationNames = array_column($relations, 'name');
        $resolvedProperties = [];

        foreach ($properties as $prop) {
            $name = $prop['name'];

            // Check if there's a conflict with a relation
            if (in_array($name, $relationNames)) {
                // If the property is numeric, rename to {name}_count
                if (in_array($prop['type'], ['number', 'int', 'integer', 'float', 'double'])) {
                    $prop['name'] = $name . '_count';
                    $resolvedProperties[] = $prop;
                }
                // If not numeric, skip the property and keep only the relation
                // This handles cases where the column and relation serve the same purpose
                continue;
            }

            $resolvedProperties[] = $prop;
        }

        return array_merge($resolvedProperties, $relations);
    }

    /**
     * Build unique TypeScript interface names, adding namespace prefix only when there are conflicts.
     *
     * @param array $classes Array of fully qualified class names
     * @param string $baseNamespace The base namespace to strip when building prefixes
     * @return array Map of class name => unique TypeScript name
     */
    protected function buildUniqueNames(array $classes, string $baseNamespace): array
    {
        $names = [];
        $shortNameCount = [];

        // First pass: count occurrences of each short name
        foreach ($classes as $class) {
            $shortName = class_basename($class);
            $shortNameCount[$shortName] = ($shortNameCount[$shortName] ?? 0) + 1;
        }

        // Second pass: assign unique names
        foreach ($classes as $class) {
            $shortName = class_basename($class);

            if ($shortNameCount[$shortName] === 1) {
                // No conflict, use short name
                $names[$class] = $shortName;
            } else {
                // Conflict detected, add namespace prefix
                $names[$class] = $this->buildPrefixedName($class, $baseNamespace);
            }
        }

        return $names;
    }

    /**
     * Build a prefixed name from namespace for conflict resolution.
     *
     * Example: App\Http\Requests\Admin\StoreUserRequest -> AdminStoreUserRequest
     */
    protected function buildPrefixedName(string $class, string $baseNamespace): string
    {
        $shortName = class_basename($class);

        // Remove the base namespace and the class name to get the relative path
        $relativePath = str_replace($baseNamespace, '', $class);
        $relativePath = str_replace('\\' . $shortName, '', $relativePath);

        if (empty($relativePath)) {
            return $shortName;
        }

        // Convert namespace parts to prefix (Admin\User -> AdminUser)
        $prefix = str_replace('\\', '', $relativePath);

        return $prefix . $shortName;
    }
}
