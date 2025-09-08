# arrau-generators

Generadores CRUD y API para Laravel.

## Instalación local

1. Clona o copia la carpeta `packages/arrau-generators` en tu proyecto.
2. Agrega en tu `composer.json` principal:

```json
"repositories": [
  {
    "type": "path",
  "url": "./packages/arrau-generators"
  }
]
```

3. Instala el paquete:

```
composer require arrau/generators:dev-main
```

4. Agrega el ServiceProvider en `config/app.php` si no se autodetecta:

```php
Arrau\Generators\GeneratorsServiceProvider::class,
```

## Uso

### CRUD

Comando:

```
php artisan make:crud Nombre [opciones]
```

Opciones relevantes:

- `--fields=`: definición rápida de campos para scaffolding de reglas y vistas. Ej: `--fields="title:string,status:enum(draft|published),amount:decimal(10,2)"`.
- `--legacy`: genera vistas en `resources/views/pages/crud/...` en lugar de `resources/views/admin/...`.
- `--no-service`, `--no-form-requests`, `--no-permissions`: desactiva partes del scaffolding.
- `--force`: sobrescribe archivos existentes.

Modelo: el modelo se genera leyendo el esquema real de la base de datos. Si la tabla existe, se construyen `fillable` desde las columnas (excluyendo `id`, timestamps y `deleted_at`) y se agregan relaciones detectadas por introspección:

- `belongsTo` detectando columnas `*_id` por claves foráneas.
- `hasOne/hasMany` en claves foráneas entrantes: si la FK tiene índice único => `hasOne`, si no => `hasMany`.
- `belongsToMany` detectando tablas pivote con convención `{singular_a}_{singular_b}` (orden alfabético) y dos FKs.
- `morphTo` si la tabla contiene pares `{name}_type`/`{name}_id`.
- `morphOne/morphMany` si otras tablas contienen pares `{name}_type`/`{name}_id`: si hay índice único sobre `{name}_type`+`{name}_id` => `morphOne`, si no => `morphMany`.

Soft deletes: si la tabla tiene `deleted_at`, se añade `SoftDeletes` automáticamente.

#### Estructura generada (CRUD)

- Controlador admin, vistas Blade (index/create/edit/show), JS inicializador, traducciones, policy, rutas admin y breadcrumbs.
- Opcionalmente Service y ServiceInterface si no usas el patrón legacy.

#### Ejemplo de modelo generado (fragmento)

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [ 'title', 'user_id', 'status' ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id', 'id');
    }

    public function comments()
    {
        return $this->hasMany(\App\Models\Comment::class, 'post_id', 'id');
    }

    public function tags()
    {
        return $this->belongsToMany(\App\Models\Tag::class, 'post_tag', 'post_id', 'tag_id');
    }

    public function imageable()
    {
        return $this->morphTo();
    }

    public function media()
    {
        return $this->morphMany(\App\Models\Media::class, 'model');
    }
}
```

### API Module

Comando:

```
php artisan make:api-module Nombre [opciones]
```

Opciones:

- `--force`: sobrescribe archivos existentes.
- `--no-bind`: no registra el binding en `AppServiceProvider`.
- `--no-filters`: no agrega sección en `config/api_filters.php` ni método `filters()` en el controlador.
- `--no-route`: no inserta la ruta en `routes/api_admin_v1.php`.
- `--no-docs`: omite los docblocks compatibles con Scribe.
- `--with-model`: genera/actualiza el modelo a partir del esquema existente (igual lógica que CRUD, incluyendo `belongsToMany` y relaciones polimórficas).

Genera:

- `App/Services/{Plural}/{Model}ServiceInterface.php`
- `App/Services/{Plural}/{Model}Service.php`
- `App/Http/Requests/Admin/{Plural}/{Model}StoreRequest.php`
- `App/Http/Requests/Admin/{Plural}/{Model}UpdateRequest.php`
- `App/Http/Resources/Admin/{Plural}/{Model}Resource.php`
- `App/Http/Controllers/Api/V1/Admin/{Plural}/{Model}Controller.php`

Además, según opciones: binding en `AppServiceProvider`, entrada en `config/api_filters.php` y ruta `Route::apiResource()` en `routes/api_admin_v1.php`.

#### Estructura del controlador API

- Extiende `App\Http\Controllers\Api\AbstractResourceController`.
- Define `$modelClass`, `$resourceClass`, `$serviceContract`, `$translationBase`.
- Incluye método `filters()` (si no se usa `--no-filters`) con soporte para `search`, `sort`, `include` y `fields` basado en `config('api_filters')`.
- Incluye docblocks Scribe (si no se usa `--no-docs`).

#### Ejemplo de controlador generado (fragmento)

```php
namespace App\Http\Controllers\Api\V1\Admin\Posts;

use App\Http\Controllers\Api\AbstractResourceController;
use App\Http\Resources\Admin\Posts\PostResource;
use App\Services\Posts\PostServiceInterface;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Posts
 * Endpoints de Posts.
 */
class PostController extends AbstractResourceController
{
    protected string $modelClass = \App\Models\Post::class;
    protected string $resourceClass = PostResource::class;
    protected string $serviceContract = PostServiceInterface::class;
    protected ?string $translationBase = 'posts';

    public function __construct(private readonly PostServiceInterface $service)
    {
        parent::__construct();
    }
}
```

### Configuración de filtros API (`config/api_filters.php`)

Ejemplo de entrada generada para `posts`:

```php
return [
  'posts' => [
    'searchable' => ['name'],
    'sortable' => ['id','created_at'],
    'includes' => [],
    'fields' => ['id','name','created_at'],
  ],
  // ... otras
];
```

### Bindings de servicios

Se añade (salvo `--no-bind`) en `App\Providers\AppServiceProvider::register()`:

```php
$this->app->bind(\App\Services\Posts\PostServiceInterface::class, \App\Services\Posts\PostService::class);
```

### Rutas API

Si no se usa `--no-route`, se inserta en `routes/api_admin_v1.php`:

```php
Route::apiResource('posts', \App\Http\Controllers\Api\V1\Admin\Posts\PostController::class);
```

## Personalización

- Modifica los archivos stub en `packages/arrau-generators/stubs/crud/` y `stubs/api/` para cambiar la estructura generada.
- Extiende los helpers y generadores en `src/Generators/` para lógica personalizada. En particular:
  - `Generators/Shared/ModelIntrospector`: lee columnas, claves foráneas y sugiere relaciones.
  - `Generators/Shared/ModelGenerator`: crea/actualiza el modelo según el esquema.
  - `Generators/Api/*`: orquesta la generación API, bindings, filtros y rutas.

## Recomendaciones de esquema para mejores resultados

- Usa claves foráneas con convención `{singular}_id`.
- Para tablas pivote, usa `{singular_a}_{singular_b}` en orden alfabético y dos FKs.
- Para relaciones polimórficas, usa `{name}_type` y `{name}_id` y considera índices únicos si quieres `morphOne`.
- Para `hasOne`, agrega índice único en la FK de la tabla hija.

## Solución de problemas

- Si no se detecta `SoftDeletes`, verifica que la tabla tenga la columna `deleted_at`.
- Si no se generan relaciones esperadas, revisa las claves foráneas en tu base de datos.
- Si las rutas no se insertan, asegúrate de que existe `routes/api_admin_v1.php`.
- Para evitar sobrescrituras, omite `--force` y verifica la existencia de archivos.

## Publicación en GitHub

1. Inicializa un repo en la carpeta del paquete:
   ```
  cd packages/arrau-generators
   git init
   git add .
   git commit -m "Initial commit"
  git remote add origin https://github.com/tuusuario/arrau-generators.git
   git push -u origin main
   ```
2. Puedes instalarlo en otros proyectos usando la sección `repositories` y `composer require` como arriba.

## Guía rápida (end-to-end)

1) Migraciones y datos (opcional)

```
php artisan migrate
php artisan db:seed
```

2) Generar CRUD base (si aplica)

```
php artisan make:crud Country --fields="name:string,code:string"
php artisan make:crud State --fields="name:string,country_id:int"
```

3) Generar API (servicios, requests, resources, controlador, rutas, filtros, bindings)

```
php artisan make:api-module Country --with-model
php artisan make:api-module State --with-model
```

4) Probar endpoints

- Autentícate si tu proyecto lo requiere.
- GET `/api/v1/admin/countries`
- GET `/api/v1/admin/states`
- POST `/api/v1/admin/countries` con body `{ "name":"Chile","code":"CL" }`

5) Regenerar documentación (si usas Scribe)

```
php artisan scribe:generate
```

6) Ajustes finos

- Revisa `config/api_filters.php` para agregar `includes` o `fields`.
- Revisa el Service para hooks `before*/after*`.
