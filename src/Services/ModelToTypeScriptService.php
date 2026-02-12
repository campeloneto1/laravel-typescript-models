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
     * Generate TypeScript interfaces for all discovered models and resources.
     */
    public function generate(): string
    {
        $this->discoveredModels = $this->discoverModels();

        // Map class names to short names for relation type resolution
        foreach ($this->discoveredModels as $modelClass) {
            $this->modelNames[$modelClass] = class_basename($modelClass);
        }

        $modelInterfaces = [];
        $modelTypes = [];
        $modelPaginatedTypes = [];

        foreach ($this->discoveredModels as $modelClass) {
            $result = $this->modelToInterface($modelClass);
            if ($result) {
                $modelInterfaces[] = $result['interface'];
                $modelTypes[] = $result['type'];
                $modelPaginatedTypes[] = $result['paginated'];
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

        // Generate Form Request interfaces
        $requestInterfaces = [];

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
            }
        }

        // Request interfaces
        if (!empty($requestInterfaces)) {
            $output .= "\n// Form Request Interfaces\n"
                . implode("\n\n", $requestInterfaces)
                . "\n";
        }

        return $output;
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

            // Merge properties and relations
            $allProperties = array_merge($properties, $relations);

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
                    'type' => 'any',
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
        if (is_null($value)) {
            return 'any';
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
                return 'any[]';
            }

            // Check if it's an associative array (object) or sequential array
            if (array_keys($value) !== range(0, count($value) - 1)) {
                return 'Record<string, any>';
            }

            return 'any[]';
        }

        if (is_object($value)) {
            // Check if it's a JsonResource
            if ($value instanceof JsonResource) {
                $resourceName = class_basename($value);
                if (in_array(get_class($value), $this->discoveredResources)) {
                    return $resourceName;
                }
            }

            return 'Record<string, any>';
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
                    $rulesArray[] = strtolower(class_basename($rule));
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
            $ruleName = explode(':', $rule)[0];

            switch ($ruleName) {
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
