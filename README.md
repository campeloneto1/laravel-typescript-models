# Laravel TypeScript Models

Automatically generate TypeScript interfaces from your Laravel Eloquent models, API Resources, and Form Requests via an API endpoint or CLI command.

## Features

- **API Resources (Recommended)**: Generate interfaces from JsonResource classes as source of truth
- **Models**: Generate TypeScript interfaces from Eloquent models (opt-in)
- **Form Requests**: Generate interfaces from FormRequest validation rules
- **Yup Schemas**: Generate Yup validation schemas from Form Requests
- **Zod Schemas**: Generate Zod validation schemas from Form Requests
- **Intelligent Type Inference**: Eliminate `any` types using PHPDoc, static analysis, and Model casts
- **Split by Domain**: Generate separate files per module (`users.ts`, `orders.ts`)
- **Union Literal Types**: Enum-like types from `in:` rules (`'admin' | 'user' | 'guest'`)
- **Pagination Types**: Auto-generated `PaginatedResponse<T>` generic type
- **Array Types**: Auto-generated array types (`Users`, `Posts`, etc.)
- **Smart Detection**: Multiple strategies for type detection (return type, PHPDoc, method body)
- **Conflict Resolution**: Automatic name prefixing when classes have the same name in different folders
- **CLI Command**: `php artisan typescript:generate` for CI/CD integration
- **Web Configurator**: Interactive HTML page to customize and download types
- **Security**: Token authentication, IP whitelist, disabled by default

## Installation

```bash
composer require campelo/laravel-typescript-models
```

The package will automatically register its service provider.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=typescript-models-config
```

### Environment Variables

```env
# Enable the endpoint (disable in production!)
TYPESCRIPT_MODELS_ENABLED=true

# Secret token for authentication
TYPESCRIPT_MODELS_TOKEN=your-secret-token-here

# Optional: Custom route path
TYPESCRIPT_MODELS_ROUTE=/api/typescript-models

# Optional: Allowed IPs (comma-separated)
TYPESCRIPT_MODELS_ALLOWED_IPS=127.0.0.1,::1

# Optional: Properties mode (fillable, database, or both)
TYPESCRIPT_MODELS_PROPERTIES_MODE=fillable

# Optional: Include/exclude features
TYPESCRIPT_MODELS_INCLUDE_MODELS=false           # Models disabled by default (use Resources!)
TYPESCRIPT_MODELS_INCLUDE_ACCESSORS=false
TYPESCRIPT_MODELS_INCLUDE_RELATIONS=true
TYPESCRIPT_MODELS_INCLUDE_RESOURCES=true
TYPESCRIPT_MODELS_INCLUDE_REQUESTS=true
TYPESCRIPT_MODELS_GENERATE_YUP_SCHEMAS=true
TYPESCRIPT_MODELS_GENERATE_ZOD_SCHEMAS=false

# Type inference for Resources (eliminates 'any' types)
TYPESCRIPT_MODELS_INFER_TYPES=true
TYPESCRIPT_MODELS_UNKNOWN_FALLBACK=unknown       # 'unknown' | 'any' | 'never'

# Split output by domain
TYPESCRIPT_MODELS_SPLIT_BY_DOMAIN=false          # 'subdirectory' | 'class' | false
TYPESCRIPT_MODELS_DOMAIN_DETECTION=subdirectory
```

## Usage

### CLI Command (Recommended)

Generate TypeScript interfaces directly from the command line:

```bash
# Generate with default options (Resources + Requests, no Models)
php artisan typescript:generate

# Specify output file
php artisan typescript:generate --output=resources/js/types/api.d.ts

# Control what to generate with --only and --include
php artisan typescript:generate --only=resources              # Only resources
php artisan typescript:generate --only=resources,requests     # Resources + Requests
php artisan typescript:generate --include=models              # Add models to default

# Legacy flags (still supported)
php artisan typescript:generate --models          # Only models
php artisan typescript:generate --resources       # Only resources
php artisan typescript:generate --requests        # Only requests

# Choose validation schema library
php artisan typescript:generate --yup            # Generate Yup schemas
php artisan typescript:generate --zod            # Generate Zod schemas
php artisan typescript:generate --yup --zod      # Generate both

# Split by domain (generates multiple files)
php artisan typescript:generate --split-by=subdirectory --output=resources/types/
# Creates: users.ts, orders.ts, _shared.ts, index.ts

# Exclude pagination/array types
php artisan typescript:generate --no-paginated --no-array-types
```

### Web Configurator

Access the interactive configurator page to customize and download your types:

```
http://localhost/api/typescript-models/configurator?token=your-secret-token
```

Features:
- Toggle models, resources, requests
- Enable/disable relations and accessors
- Choose between Yup and Zod schemas
- Preview before download
- Copy generated URL for CI/CD

### API Endpoint

```bash
# Using token in header
curl -H "X-TypeScript-Token: your-secret-token" http://localhost/api/typescript-models

# Using Bearer token
curl -H "Authorization: Bearer your-secret-token" http://localhost/api/typescript-models

# Using query parameter
curl "http://localhost/api/typescript-models?token=your-secret-token"

# With custom options
curl "http://localhost/api/typescript-models?token=your-token&resources=1&requests=1&yup=1&zod=0"
```

### Integrating with Your Frontend

```json
// package.json
{
  "scripts": {
    "types:generate": "curl -H 'X-TypeScript-Token: your-token' http://localhost/api/typescript-models > src/types/models.d.ts"
  }
}
```

---

## Resources vs Models: Best Practices

**Resources are the recommended source of truth** for frontend types because:
- They represent the actual API response shape
- They hide internal fields that shouldn't be exposed
- Multiple Resources can exist for the same Model (summary, detail, admin)

By default, **Models are disabled** (`include_models=false`). Enable them only if needed:

```bash
# Default: Only Resources + Requests
php artisan typescript:generate

# Include Models for admin panels or internal tools
php artisan typescript:generate --include=models

# Or via config
TYPESCRIPT_MODELS_INCLUDE_MODELS=true
```

---

## Split by Domain

For large codebases, split generated types into separate files:

```bash
php artisan typescript:generate --split-by=subdirectory --output=resources/types/
```

### Directory Structure

Given this Resource structure:
```
app/Http/Resources/
├── Users/
│   ├── UserResource.php
│   └── UserSummaryResource.php
├── Orders/
│   └── OrderResource.php
└── ProductResource.php  (no subdirectory)
```

Generates:
```
resources/types/
├── _shared.ts     # PaginatedResponse, PaginationLink
├── index.ts       # Re-exports everything
├── users.ts       # UserResource, UserSummaryResource
├── orders.ts      # OrderResource
└── default.ts     # ProductResource (no subdirectory)
```

### Generated Files

**_shared.ts**
```typescript
export interface PaginatedResponse<T> { ... }
export interface PaginationLink { ... }
```

**users.ts**
```typescript
import type { PaginatedResponse } from './_shared';

export interface UserResource { ... }
export interface UserSummaryResource { ... }
export type UserResources = UserResource[];
export type UserResourcesPaginated = PaginatedResponse<UserResource>;
```

**index.ts**
```typescript
export * from './_shared';
export * from './users';
export * from './orders';
export * from './default';
```

### Detection Modes

| Mode | Description | Example |
|------|-------------|---------|
| `subdirectory` | Uses namespace subdirectory | `Resources\Users\` → `users.ts` |
| `class` | Groups by class name prefix | `UserResource` → `user.ts` |

---

## 1. Model Interfaces

Generate TypeScript interfaces from your Eloquent models.

### Laravel Model

```php
// app/Models/User.php
class User extends Model
{
    protected $fillable = ['name', 'email', 'birth_date'];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}
```

### Generated TypeScript

```typescript
// Model Interfaces
export interface User {
  id: number;
  name?: string;
  email?: string;
  birth_date?: Date;
  created_at?: Date;
  updated_at?: Date;
  posts?: Post[];
  profile?: Profile;
}

export interface Post {
  id: number;
  title?: string;
  content?: string;
  user_id?: number;
  created_at?: Date;
  updated_at?: Date;
  user?: User;
}

// Model Array Types
export type Users = User[];
export type Posts = Post[];

// Model Paginated Types
export type UsersPaginated = PaginatedResponse<User>;
export type PostsPaginated = PaginatedResponse<Post>;
```

### Relationship Detection

The package uses **3 strategies** to detect relationships:

1. **Return Type (Recommended)**
```php
public function posts(): HasMany
{
    return $this->hasMany(Post::class);
}
```

2. **PHPDoc Annotation**
```php
/** @return HasMany */
public function posts()
{
    return $this->hasMany(Post::class);
}
```

3. **Method Body Analysis**
```php
public function posts()
{
    return $this->hasMany(Post::class); // Auto-detected!
}
```

### Supported Relationships

| Relationship | TypeScript Type |
|--------------|-----------------|
| `HasOne`, `BelongsTo`, `MorphOne`, `MorphTo`, `HasOneThrough` | `RelatedModel` |
| `HasMany`, `BelongsToMany`, `MorphMany`, `MorphToMany`, `HasManyThrough` | `RelatedModel[]` |

---

## 2. API Resource Interfaces

Generate interfaces from your API Resources. Useful when your API response differs from the model structure.

### Laravel Resources

```php
// app/Http/Resources/UserResource.php
class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'full_name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->getAvatarUrl(),
            'member_since' => $this->created_at->format('Y-m-d'),
        ];
    }
}

// app/Http/Resources/UserSummaryResource.php
class UserSummaryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

// app/Http/Resources/UserProfileResource.php
class UserProfileResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'full_name' => $this->name,
            'email' => $this->email,
            'bio' => $this->profile->bio,
            'posts_count' => $this->posts->count(),
        ];
    }
}
```

### Generated TypeScript (with Intelligent Type Inference)

```typescript
// Resource Interfaces - No more 'any' types!
export interface UserResource {
  avatar_url?: string;        // Inferred from name pattern (*_url)
  email?: string;             // Inferred from name pattern
  full_name?: string;         // Inferred from name pattern
  id?: number;                // Inferred from name pattern
  member_since?: string;      // Inferred from ->format() call
}

export interface UserSummaryResource {
  id?: number;
  name?: string;
}

export interface UserProfileResource {
  bio?: string;
  email?: string;
  full_name?: string;
  id?: number;
  posts_count?: number;       // Inferred from name pattern (*_count)
}

// Resource Array Types
export type UserResources = UserResource[];
export type UserSummaryResources = UserSummaryResource[];
export type UserProfileResources = UserProfileResource[];

// Resource Paginated Types
export type UserResourcesPaginated = PaginatedResponse<UserResource>;
export type UserSummaryResourcesPaginated = PaginatedResponse<UserSummaryResource>;
export type UserProfileResourcesPaginated = PaginatedResponse<UserProfileResource>;
```

### PHPDoc Type Hints (Recommended)

For full control over types, use PHPDoc annotations:

```php
/**
 * @property int $id
 * @property string $full_name
 * @property string $email
 * @property string $avatar_url
 * @property PostResource[] $posts
 */
class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'full_name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->getAvatarUrl(),
            'posts' => PostResource::collection($this->posts),
        ];
    }
}
```

### How Type Inference Works

The package uses **multiple strategies** to determine types (in priority order):

1. **PHPDoc `@property`**: Highest priority, most reliable
2. **Static Analysis**: Detects patterns in `toArray()`:
   - `PostResource::collection(...)` → `PostResource[]`
   - `new UserResource(...)` → `UserResource`
   - `(bool) $value` → `boolean`
   - `->format('Y-m-d')` → `string`
3. **Model Casts**: Inherits types from the underlying Model
4. **Name Patterns**:
   - `*_id`, `id` → `number`
   - `*_at`, `*_date` → `string`
   - `is_*`, `has_*`, `can_*` → `boolean`
   - `*_count` → `number`
   - `*_url`, `email`, `name` → `string`
5. **Fallback**: `unknown` (configurable via `unknown_type_fallback`)

---

## 3. Form Request Interfaces

Generate interfaces from your Form Request validation rules. Perfect for typing your frontend forms.

### Laravel Form Requests

```php
// app/Http/Requests/StoreUserRequest.php
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'age' => 'nullable|integer|min:18',
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,id',
            'avatar' => 'nullable|image|max:2048',
        ];
    }
}

// app/Http/Requests/UpdateUserRequest.php
class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'bio' => 'nullable|string|max:1000',
        ];
    }
}
```

### Generated TypeScript

```typescript
// Form Request Interfaces
export interface StoreUserRequest {
  age?: number;
  avatar?: File;
  email: string;
  name: string;
  password: string;
  roles: any[];
}

export interface UpdateUserRequest {
  bio?: string;
  email?: string;
  name?: string;
}
```

### Type Mapping from Validation Rules

| Laravel Rule | TypeScript Type |
|--------------|-----------------|
| `integer`, `numeric` | `number` |
| `boolean`, `accepted` | `boolean` |
| `array` | `any[]` |
| `file`, `image`, `mimes` | `File` |
| `json` | `Record<string, any>` |
| `string`, `email`, `url`, `uuid`, `date` | `string` |
| `required` | Required field (no `?`) |
| `nullable`, `sometimes` | Optional field (`?`) |

### Conflict Resolution

When you have requests with the same name in different folders, the package automatically adds a prefix:

```
app/Http/Requests/
├── StoreUserRequest.php         → StoreUserRequest
├── UpdateUserRequest.php        → UpdateUserRequest
└── Admin/
    └── StoreUserRequest.php     → AdminStoreUserRequest
    └── Api/
        └── V1/
            └── StoreUserRequest.php → AdminApiV1StoreUserRequest
```

---

## 4. Yup Validation Schemas

Generate Yup schemas from your Form Requests for client-side validation.

### Laravel Form Request

```php
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:255',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'age' => 'nullable|integer|min:18|max:120',
            'website' => 'nullable|url',
            'role' => 'required|in:admin,user,guest',
        ];
    }
}
```

### Generated Yup Schema

```typescript
// Yup Validation Schemas
// Usage: import * as yup from 'yup';

export const StoreUserRequestSchema = yup.object({
  name: yup.string().required('This field is required').min(2, 'Must be at least 2 characters').max(255, 'Must be at most 255 characters'),
  email: yup.string().required('This field is required').email('Invalid email address'),
  password: yup.string().required('This field is required').min(8, 'Must be at least 8 characters').oneOf([yup.ref('password_confirmation')], 'Must match confirmation'),
  age: yup.number().nullable().min(18, 'Must be at least 18').max(120, 'Must be at most 120'),
  website: yup.string().nullable().url('Invalid URL'),
  role: yup.string().required('This field is required').oneOf(['admin', 'user', 'guest'], 'Invalid value'),
});
```

### Using in React/Vue

```tsx
// React example with react-hook-form
import { useForm } from 'react-hook-form';
import { yupResolver } from '@hookform/resolvers/yup';
import { StoreUserRequest, StoreUserRequestSchema } from '@/types/models';

function UserForm() {
  const { register, handleSubmit, formState: { errors } } = useForm<StoreUserRequest>({
    resolver: yupResolver(StoreUserRequestSchema)
  });

  const onSubmit = (data: StoreUserRequest) => {
    // data is typed!
    api.post('/users', data);
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <input {...register('name')} />
      {errors.name && <span>{errors.name.message}</span>}
      {/* ... */}
    </form>
  );
}
```

### Supported Yup Validations

| Laravel Rule | Yup Method |
|--------------|------------|
| `required` | `.required()` |
| `nullable` | `.nullable()` |
| `email` | `.email()` |
| `url` | `.url()` |
| `uuid` | `.uuid()` |
| `min:n` | `.min(n)` |
| `max:n` | `.max(n)` |
| `between:a,b` | `.min(a).max(b)` |
| `size:n` | `.length(n)` |
| `in:a,b,c` | `.oneOf(['a','b','c'])` |
| `confirmed` | `.oneOf([yup.ref('field_confirmation')])` |
| `integer` | `.integer()` |
| `regex` | `.matches()` |

---

## 5. Zod Validation Schemas

Generate Zod schemas from your Form Requests. Zod is a TypeScript-first schema validation library.

### Enable Zod Schemas

```env
TYPESCRIPT_MODELS_GENERATE_ZOD_SCHEMAS=true
```

### Laravel Form Request

```php
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:255',
            'email' => 'required|email',
            'role' => 'required|in:admin,user,guest',
            'age' => 'nullable|integer|min:18',
        ];
    }
}
```

### Generated Zod Schema

```typescript
// Zod Validation Schemas
// Usage: import { z } from 'zod';

export const StoreUserRequestSchema = z.object({
  name: z.string().min(2, { message: 'Must be at least 2 characters' }).max(255, { message: 'Must be at most 255 characters' }),
  email: z.string().email({ message: 'Invalid email address' }),
  role: z.enum(['admin', 'user', 'guest']),
  age: z.number().min(18, { message: 'Must be at least 18' }).nullable().optional(),
});
```

### Using in React

```tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { StoreUserRequestSchema } from '@/types/models';

type StoreUserRequest = z.infer<typeof StoreUserRequestSchema>;

function UserForm() {
  const { register, handleSubmit, formState: { errors } } = useForm<StoreUserRequest>({
    resolver: zodResolver(StoreUserRequestSchema)
  });

  // ...
}
```

---

## 6. Enum Union Literal Types

The package automatically generates TypeScript union literal types from `in:` validation rules and `Rule::in()`:

### Laravel Validation

```php
// Using string rule
'role' => 'required|in:admin,user,guest',

// Using Rule::in()
'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],

// Using Enum rule (Laravel 9+)
'type' => ['required', Rule::enum(OrderType::class)],
```

### Generated TypeScript

```typescript
export interface StoreOrderRequest {
  role: 'admin' | 'user' | 'guest';
  status: 'pending' | 'approved' | 'rejected';
  type: 'delivery' | 'pickup' | 'dine_in';
}
```

This provides much stronger typing than generic `string` types!

---

## 7. Pagination Types

Auto-generated generic pagination interfaces:

```typescript
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
```

### Usage

```typescript
// Fetch paginated users
const response = await api.get<UsersPaginated>('/users');

console.log(response.data);        // User[]
console.log(response.total);       // number
console.log(response.current_page); // number
```

---

## Configuration Options

### Models

```php
'include_models' => false, // Disabled by default (use Resources!)

'models_paths' => [
    app_path('Models'),
],

'exclude_models' => [
    // App\Models\SomeModel::class,
],

'properties_mode' => 'fillable', // fillable, database, or both

'include_accessors' => false,

'include_relations' => true,
```

### Resources

```php
'include_resources' => true,

'resources_paths' => [
    app_path('Http/Resources'),
],

'exclude_resources' => [
    // App\Http\Resources\SomeResource::class,
],

// Type inference to eliminate 'any' types
'infer_resource_types' => true,

// What to use when type cannot be inferred
'unknown_type_fallback' => 'unknown', // 'unknown' | 'any' | 'never'
```

### Form Requests

```php
'include_requests' => true,

'requests_paths' => [
    app_path('Http/Requests'),
],

'exclude_requests' => [
    // App\Http\Requests\SomeRequest::class,
],

'generate_yup_schemas' => true,
'generate_zod_schemas' => false,
```

### Split by Domain

```php
// Split output into multiple files
'split_by_domain' => false, // 'subdirectory' | 'class' | false

// How to detect domain from class namespace
'domain_detection' => 'subdirectory', // 'subdirectory' | 'class_basename'
```

---

## Complete Output Example

```typescript
// =============================================================================
// Auto-generated TypeScript interfaces from Laravel Models
// Generated at: 2024-01-15T10:30:00+00:00
// Do not edit this file manually - it will be overwritten
// =============================================================================

// Pagination Interfaces
export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  // ... other pagination fields
}

// Resource Interfaces (with intelligent type inference - no 'any'!)
export interface UserResource {
  id?: number;
  full_name?: string;
  avatar_url?: string;
  posts?: PostResource[];
}

export interface UserSummaryResource {
  id?: number;
  name?: string;
}

// Resource Array Types
export type UserResources = UserResource[];
export type UserSummaryResources = UserSummaryResource[];

// Resource Paginated Types
export type UserResourcesPaginated = PaginatedResponse<UserResource>;
export type UserSummaryResourcesPaginated = PaginatedResponse<UserSummaryResource>;

// Form Request Interfaces
export interface StoreUserRequest {
  email: string;
  name: string;
  password: string;
  age?: number;
}

export interface UpdateUserRequest {
  email?: string;
  name?: string;
}

// Yup Validation Schemas
// Usage: import * as yup from 'yup';
export const StoreUserRequestSchema = yup.object({
  email: yup.string().required('This field is required').email('Invalid email address'),
  name: yup.string().required('This field is required').max(255, 'Must be at most 255 characters'),
  password: yup.string().required('This field is required').min(8, 'Must be at least 8 characters'),
  age: yup.number().nullable().min(18, 'Must be at least 18'),
});

export const UpdateUserRequestSchema = yup.object({
  email: yup.string().optional().email('Invalid email address'),
  name: yup.string().optional().max(255, 'Must be at most 255 characters'),
});
```

---

## Security Recommendations

1. **Never enable in production** - Set `TYPESCRIPT_MODELS_ENABLED=false`
2. **Use strong tokens** - Generate a secure random token
3. **Restrict IPs** - Limit to localhost or known development IPs
4. **Use HTTPS** - Always use HTTPS when transmitting tokens
5. **Exclude sensitive models** - Don't expose internal/admin models

---

## Type Mapping Reference

### PHP/Laravel to TypeScript

| PHP/Laravel Type | TypeScript Type |
|------------------|-----------------|
| `int`, `integer` | `number` |
| `float`, `double`, `decimal` | `number` |
| `bool`, `boolean` | `boolean` |
| `array`, `json`, `collection` | `any[]` |
| `object` | `Record<string, any>` |
| `datetime`, `date`, `timestamp` | `Date` |
| `string` (default) | `string` |

### Validation Rules to TypeScript

| Laravel Rule | TypeScript Type |
|--------------|-----------------|
| `integer`, `numeric` | `number` |
| `boolean`, `accepted` | `boolean` |
| `array` | `any[]` |
| `file`, `image` | `File` |
| `json` | `Record<string, any>` |
| Other | `string` |

---

## License

MIT License - see [LICENSE](LICENSE) file.
