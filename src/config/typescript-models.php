<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable the TypeScript Models Endpoint
    |--------------------------------------------------------------------------
    |
    | This option controls whether the TypeScript models endpoint is enabled.
    | For security reasons, this should be disabled in production environments.
    |
    */
    'enabled' => env('TYPESCRIPT_MODELS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the route path and middleware for the TypeScript endpoint.
    |
    */
    'route' => env('TYPESCRIPT_MODELS_ROUTE', '/api/typescript-models'),

    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist
    |--------------------------------------------------------------------------
    |
    | Only requests from these IP addresses will be allowed. Leave empty to
    | allow all IPs (not recommended for production).
    |
    */
    'allowed_ips' => array_filter(
        explode(',', env('TYPESCRIPT_MODELS_ALLOWED_IPS', '127.0.0.1,::1'))
    ),

    /*
    |--------------------------------------------------------------------------
    | Token Authentication
    |--------------------------------------------------------------------------
    |
    | Require a token for authentication. The token can be sent via:
    | - Header: X-TypeScript-Token
    | - Header: Authorization (Bearer token)
    | - Query parameter: ?token=xxx
    |
    */
    'require_token' => env('TYPESCRIPT_MODELS_REQUIRE_TOKEN', true),

    'token' => env('TYPESCRIPT_MODELS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Include Models
    |--------------------------------------------------------------------------
    |
    | Whether to generate TypeScript interfaces from Eloquent Models.
    | By default, this is disabled because Resources are the recommended
    | source of truth for frontend types (they represent the actual API shape).
    | Enable this if you need direct Model types for internal tools or admin panels.
    |
    */
    'include_models' => env('TYPESCRIPT_MODELS_INCLUDE_MODELS', false),

    /*
    |--------------------------------------------------------------------------
    | Models Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which models to include and how to discover them.
    |
    */
    'models_paths' => [
        app_path('Models'),
    ],

    'exclude_models' => [
        // App\Models\SomeModel::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Properties Mode
    |--------------------------------------------------------------------------
    |
    | How to determine which properties to include in the TypeScript interface:
    | - 'fillable': Only include $fillable properties (safer, recommended)
    | - 'database': Read columns directly from database schema
    | - 'both': Combine fillable and database columns
    |
    */
    'properties_mode' => env('TYPESCRIPT_MODELS_PROPERTIES_MODE', 'fillable'),

    /*
    |--------------------------------------------------------------------------
    | Include Accessors
    |--------------------------------------------------------------------------
    |
    | Whether to include model accessors (getXxxAttribute methods and
    | Attribute casts) in the generated interfaces.
    |
    */
    'include_accessors' => env('TYPESCRIPT_MODELS_INCLUDE_ACCESSORS', false),

    /*
    |--------------------------------------------------------------------------
    | Include Relations
    |--------------------------------------------------------------------------
    |
    | Whether to include model relationships in the generated interfaces.
    | The package will automatically detect the related model type using:
    | 1. Return type hints (e.g., `: HasMany`)
    | 2. PHPDoc annotations (e.g., `@return HasMany`)
    | 3. Method body analysis (e.g., `$this->hasMany(...)`)
    |
    */
    'include_relations' => env('TYPESCRIPT_MODELS_INCLUDE_RELATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Include Resources
    |--------------------------------------------------------------------------
    |
    | Whether to generate TypeScript interfaces from API Resources.
    | This allows generating types that match the actual API response shape,
    | which may differ from the underlying Model structure.
    |
    */
    'include_resources' => env('TYPESCRIPT_MODELS_INCLUDE_RESOURCES', true),

    /*
    |--------------------------------------------------------------------------
    | Resources Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which API Resources to include and how to discover them.
    |
    */
    'resources_paths' => [
        app_path('Http/Resources'),
    ],

    'exclude_resources' => [
        // App\Http\Resources\SomeResource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Include Form Requests
    |--------------------------------------------------------------------------
    |
    | Whether to generate TypeScript interfaces from Form Requests.
    | This generates types that match the expected input for API endpoints,
    | based on validation rules defined in the Form Request classes.
    |
    */
    'include_requests' => env('TYPESCRIPT_MODELS_INCLUDE_REQUESTS', true),

    /*
    |--------------------------------------------------------------------------
    | Requests Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which Form Requests to include and how to discover them.
    |
    */
    'requests_paths' => [
        app_path('Http/Requests'),
    ],

    'exclude_requests' => [
        // App\Http\Requests\SomeRequest::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generate Yup Schemas
    |--------------------------------------------------------------------------
    |
    | Whether to generate Yup validation schemas from Form Requests.
    | This creates client-side validation that mirrors your Laravel rules.
    | Requires 'include_requests' to be enabled.
    |
    */
    'generate_yup_schemas' => env('TYPESCRIPT_MODELS_GENERATE_YUP_SCHEMAS', true),

    /*
    |--------------------------------------------------------------------------
    | Generate Zod Schemas
    |--------------------------------------------------------------------------
    |
    | Whether to generate Zod validation schemas from Form Requests.
    | Zod is a TypeScript-first schema validation library.
    | Requires 'include_requests' to be enabled.
    |
    */
    'generate_zod_schemas' => env('TYPESCRIPT_MODELS_GENERATE_ZOD_SCHEMAS', false),

    /*
    |--------------------------------------------------------------------------
    | Resource Type Inference
    |--------------------------------------------------------------------------
    |
    | Enable intelligent type inference for Resources to avoid 'any' types.
    | When enabled, the package will try to infer types from:
    | - PHPDoc @property annotations on the Resource class
    | - Static analysis of the toArray() method
    | - The underlying Model's casts and properties
    |
    */
    'infer_resource_types' => env('TYPESCRIPT_MODELS_INFER_TYPES', true),

    /*
    |--------------------------------------------------------------------------
    | Unknown Type Fallback
    |--------------------------------------------------------------------------
    |
    | What type to use when inference fails:
    | - 'unknown': TypeScript's unknown type (safest, requires type checks)
    | - 'any': TypeScript's any type (flexible but less safe)
    | - 'never': Strict mode, will cause compile errors if used
    |
    */
    'unknown_type_fallback' => env('TYPESCRIPT_MODELS_UNKNOWN_FALLBACK', 'unknown'),

    /*
    |--------------------------------------------------------------------------
    | Split by Domain
    |--------------------------------------------------------------------------
    |
    | Split the generated TypeScript files by domain/module.
    | This helps with large codebases by organizing types into separate files.
    |
    | Options:
    | - false: Generate a single file (default)
    | - 'subdirectory': Split based on subdirectory structure
    |   (App\Http\Resources\Users\UserResource -> users.ts)
    | - 'class': Split based on class name prefix
    |   (UserResource, UserSummaryResource -> user.ts)
    |
    */
    'split_by_domain' => env('TYPESCRIPT_MODELS_SPLIT_BY_DOMAIN', false),

    /*
    |--------------------------------------------------------------------------
    | Domain Detection Mode
    |--------------------------------------------------------------------------
    |
    | How to detect the domain for split-by-domain feature:
    | - 'subdirectory': Use first subdirectory after base path
    | - 'class_basename': Group by class name prefix (e.g., User*, Order*)
    |
    */
    'domain_detection' => env('TYPESCRIPT_MODELS_DOMAIN_DETECTION', 'subdirectory'),
];
