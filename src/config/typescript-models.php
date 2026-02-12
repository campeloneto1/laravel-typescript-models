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
];
