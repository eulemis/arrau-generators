<?php
namespace Arrau\Generators\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Arrau\Generators\Helpers\StubWriter;
use Arrau\Generators\Helpers\FieldParser;
use Arrau\Generators\Helpers\PermissionManager;

class MakeApiModuleCommand extends Command
{
    protected $signature = 'make:api-module
        {name : Nombre del modelo (singular, p.ej. Post)}
        {--force : Sobrescribir si existe}
        {--no-bind : No registrar binding en AppServiceProvider}
        {--no-filters : No añadir sección en config/api_filters.php ni método filters()}
        {--no-route : No insertar la ruta en routes/api_admin_v1.php}
        {--no-docs : Generar sin docblocks Scribe}';
    protected $description = 'Genera estructura API (Controller, Service, Interface, Requests, Resource, filtros, rutas y bindings)';

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $pluralStudly = Str::studly(Str::pluralStudly($name));
        $pluralSnake = Str::snake(Str::pluralStudly($name));

        $paths = $this->paths($name, $pluralStudly);

        foreach ($paths as $p) {
            if (file_exists($p['path']) && !$this->option('force')) {
                $this->error("Existe: {$p['path']} (usa --force para sobrescribir)");
                return self::FAILURE;
            }
        }

        foreach ($paths as $p) {
            if (!is_dir(dirname($p['path']))) {
                mkdir(dirname($p['path']), 0777, true);
            }
        }

        foreach ($paths as $p) {
            $code = $this->{$p['stub']}($name, $pluralStudly);
            file_put_contents($p['path'], $code);
            $this->line('Creado: '.$p['path']);
        }

        if (!$this->option('no-bind')) {
            $this->updateAppServiceProvider($name, $pluralStudly);
        } else {
            $this->line('[skip] binding (--no-bind)');
        }

        if (!$this->option('no-filters')) {
            $this->updateApiFiltersConfig($pluralSnake);
        } else {
            $this->line('[skip] filtros (--no-filters)');
        }

        if (!$this->option('no-route')) {
            $this->updateRoutesFile($name, $pluralStudly);
        } else {
            $this->line('[skip] ruta (--no-route)');
        }

        $this->info('Estructura API generada');
        if ($this->option('no-route')) {
            $this->line("Ruta sugerida (routes/api_admin_v1.php):\nRoute::apiResource('{$pluralSnake}', \\App\\Http\\Controllers\\Api\\V1\\Admin\\{$pluralStudly}\\{$name}Controller::class);");
        }
        $this->warn("Recuerda crear migración y modelo App\\Models\\{$name} si no existe.");
        return self::SUCCESS;
    }

    private function paths(string $name, string $pluralStudly): array
    {
        return [
            ['path' => app_path("Services/{$pluralStudly}/{$name}ServiceInterface.php"), 'stub' => 'serviceInterfaceStub'],
            ['path' => app_path("Services/{$pluralStudly}/{$name}Service.php"),          'stub' => 'serviceStub'],
            ['path' => app_path("Http/Requests/Admin/{$pluralStudly}/{$name}StoreRequest.php"), 'stub' => 'storeRequestStub'],
            ['path' => app_path("Http/Requests/Admin/{$pluralStudly}/{$name}UpdateRequest.php"), 'stub' => 'updateRequestStub'],
            ['path' => app_path("Http/Resources/Admin/{$pluralStudly}/{$name}Resource.php"), 'stub' => 'resourceStub'],
            ['path' => app_path("Http/Controllers/Api/V1/Admin/{$pluralStudly}/{$name}Controller.php"), 'stub' => 'controllerStub'],
        ];
    }

    private function serviceInterfaceStub(string $name, string $pluralStudly): string
    {
        $tpl = <<<'TPL'
<?php
namespace App\Services\{{PLURAL}};

use App\Models\{{MODEL}};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface {{MODEL}}ServiceInterface
{
    public function query(): \Illuminate\Database\Eloquent\Builder;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function create(array $data): {{MODEL}};
    public function update({{MODEL}} $instance, array $data): {{MODEL}};
    public function delete({{MODEL}} $instance): void;
}
TPL;
        return $this->renderTemplate($tpl, [
            '{{PLURAL}}' => $pluralStudly,
            '{{MODEL}}' => $name,
        ]);
    }

    private function serviceStub(string $name, string $pluralStudly): string
    {
        $tpl = <<<'TPL'
<?php
namespace App\Services\{{PLURAL}};

use App\Models\{{MODEL}};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class {{MODEL}}Service implements {{MODEL}}ServiceInterface
{
    public function query(): \Illuminate\Database\Eloquent\Builder
    {
        return {{MODEL}}::query();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return {{MODEL}}::query()->latest()->paginate($perPage);
    }

    public function create(array $data): {{MODEL}}
    {
        $data = $this->beforeCreate($data);
        $instance = {{MODEL}}::create($data);
        $this->afterCreate($instance);
        return $instance;
    }

    public function update({{MODEL}} $instance, array $data): {{MODEL}}
    {
        $data = $this->beforeUpdate($instance, $data);
        $instance->update($data);
        $this->afterUpdate($instance);
        return $instance;
    }

    public function delete({{MODEL}} $instance): void
    {
        $this->beforeDelete($instance);
        $instance->delete();
        $this->afterDelete($instance);
    }

    protected function beforeCreate(array $data): array { return $data; }
    protected function afterCreate({{MODEL}} $instance): void {}
    protected function beforeUpdate({{MODEL}} $instance, array $data): array { return $data; }
    protected function afterUpdate({{MODEL}} $instance): void {}
    protected function beforeDelete({{MODEL}} $instance): void {}
    protected function afterDelete({{MODEL}} $instance): void {}
}
TPL;
        return $this->renderTemplate($tpl, [
            '{{PLURAL}}' => $pluralStudly,
            '{{MODEL}}' => $name,
        ]);
    }

    private function storeRequestStub(string $name, string $pluralStudly): string
    {
        $tpl = <<<'TPL'
<?php
namespace App\Http\Requests\Admin\{{PLURAL}};

use Illuminate\Foundation\Http\FormRequest;

class {{MODEL}}StoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return [ 'name' => 'required|string|max:150' ]; }
    public function bodyParameters(): array { return ['name' => ['description' => 'Nombre', 'example' => '{{MODEL}} demo']]; }
}
TPL;
        return $this->renderTemplate($tpl, [
            '{{PLURAL}}' => $pluralStudly,
            '{{MODEL}}' => $name,
        ]);
    }

    private function updateRequestStub(string $name, string $pluralStudly): string
    {
        $tpl = <<<'TPL'
<?php
namespace App\Http\Requests\Admin\{{PLURAL}};

use Illuminate\Foundation\Http\FormRequest;

class {{MODEL}}UpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return [ 'name' => 'required|string|max:150' ]; }
    public function bodyParameters(): array { return ['name' => ['description' => 'Nombre', 'example' => '{{MODEL}} editado']]; }
}
TPL;
        return $this->renderTemplate($tpl, [
            '{{PLURAL}}' => $pluralStudly,
            '{{MODEL}}' => $name,
        ]);
    }

    private function resourceStub(string $name, string $pluralStudly): string
    {
        $tpl = <<<'TPL'
<?php
namespace App\Http\Resources\Admin\{{PLURAL}};

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class {{MODEL}}Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
TPL;
        return $this->renderTemplate($tpl, [
            '{{PLURAL}}' => $pluralStudly,
            '{{MODEL}}' => $name,
        ]);
    }

    private function controllerStub(string $name, string $pluralStudly): string
    {
        $pluralSnake = Str::snake($pluralStudly);
        $groupName = Str::headline($pluralStudly);
        $withFilters = !$this->option('no-filters');
        $withDocs = !$this->option('no-docs');

        $importsFilters = $withFilters ? "use App\\Api\\Filtering\\{$pluralStudly}\\SearchFilter;\nuse App\\Api\\Filtering\\{$pluralStudly}\\SortFilter;\nuse App\\Api\\Filtering\\Common\\IncludeFilter;\n" : '';

        $filtersMethod = '';
        if ($withFilters) {
            $filtersMethod = <<<'FILT'
    protected function filters(): array
    {
        $cfg = config('api_filters.{{PLURAL_SNAKE}}', []);
        return [
            'search'  => new SearchFilter($cfg['searchable'] ?? []),
            'sort'    => new SortFilter($cfg['sortable'] ?? []),
            'include' => new IncludeFilter($cfg['includes'] ?? []),
            'fields'  => new \App\Api\Filtering\Common\FieldFilter($cfg['fields'] ?? []),
        ];
    }

FILT;
            $filtersMethod = strtr($filtersMethod, ['{{PLURAL_SNAKE}}' => $pluralSnake]);
        }

        $docGroup = $withDocs ? "/**\n * @group Admin {$groupName}\n * Endpoints de {$groupName}.\n */" : '';
        $docIndex = $withDocs ? <<<'DOC'
    /**
     * Listar recursos.
     * @authenticated
     * @queryParam page integer Página actual. Example: 1
     * @queryParam per_page integer Elementos por página (1-100). Example: 15
     * @queryParam search string Búsqueda parcial. Example: demo
     * @queryParam sort string Campo de orden (prefijo - para DESC). Example: -created_at
     * @queryParam include string Relaciones a incluir separadas por coma. Example: 
     * @queryParam fields string Subconjunto de campos a devolver. Example: id,name,created_at
     * @response 200 scenario=success {"success":true,"data":[],"meta":{"current_page":1,"per_page":15}}
     */
    // index heredado de AbstractResourceController
DOC
        : '';
        $docStore = $withDocs ? <<<'DOC'
    /**
     * Crear {{MODEL}}.
     * @authenticated
     * @bodyParam name string required Nombre.
     * @response 201 {"success":true,"message":"Created","data":{"id":1}}
     * @response 422 {"success":false,"message":"Validation error"}
     */
DOC
        : '';
        $docShow = $withDocs ? <<<'DOC'
    /**
     * Mostrar un {{MODEL}}.
     * @authenticated
     * @response 200 {"success":true,"data":{"id":1}}
     * @response 404 {"success":false,"message":"Not Found"}
     */
DOC
        : '';
        $docUpdate = $withDocs ? <<<'DOC'
    /**
     * Actualizar {{MODEL}}.
     * @authenticated
     * @bodyParam name string Nombre.
     * @response 200 {"success":true,"message":"Updated","data":{"id":1}}
     * @response 404 {"success":false,"message":"Not Found"}
     * @response 422 {"success":false,"message":"Validation error"}
     */
DOC
        : '';
        $docDestroy = $withDocs ? <<<'DOC'
    /**
     * Eliminar {{MODEL}}.
     * @authenticated
     * @response 200 {"success":true,"message":"Deleted"}
     * @response 404 {"success":false,"message":"Not Found"}
     */
DOC
        : '';

        $tpl = <<<'TPL'
<?php
namespace App\Http\Controllers\Api\V1\Admin\{{PLURAL}};

use App\Http\Controllers\Api\AbstractResourceController;
use App\Http\Resources\Admin\{{PLURAL}}\{{MODEL}}Resource;
use App\Services\{{PLURAL}}\{{MODEL}}ServiceInterface;
use Illuminate\Http\JsonResponse;
{{IMPORTS_FILTERS}}
{{DOC_GROUP}}
class {{MODEL}}Controller extends AbstractResourceController
{
    protected string $modelClass = \App\Models\{{MODEL}}::class;
    protected string $resourceClass = {{MODEL}}Resource::class;
    protected string $serviceContract = {{MODEL}}ServiceInterface::class;
    protected ?string $translationBase = '{{PLURAL_SNAKE}}';

    public function __construct(private readonly {{MODEL}}ServiceInterface $service)
    {
        parent::__construct();
    }

{{DOC_INDEX}}
{{FILTERS_METHOD}}    {{DOC_STORE}}public function store($request): JsonResponse { return parent::store($request); }
    {{DOC_SHOW}}public function show($model): JsonResponse { return parent::show($model); }
    {{DOC_UPDATE}}public function update($request, $model): JsonResponse { return parent::update($request, $model); }
    {{DOC_DESTROY}}public function destroy($model): JsonResponse { return parent::destroy($model); }
}
TPL;

        $replacements = [
            '{{PLURAL}}' => $pluralStudly,
            '{{PLURAL_SNAKE}}' => $pluralSnake,
            '{{MODEL}}' => $name,
            '{{DOC_GROUP}}' => $docGroup,
            '{{DOC_INDEX}}' => $docIndex ? $docIndex."\n" : '',
            '{{DOC_STORE}}' => $docStore ? $docStore."\n    " : '',
            '{{DOC_SHOW}}' => $docShow ? $docShow."\n    " : '',
            '{{DOC_UPDATE}}' => $docUpdate ? $docUpdate."\n    " : '',
            '{{DOC_DESTROY}}' => $docDestroy ? $docDestroy."\n    " : '',
            '{{FILTERS_METHOD}}' => $filtersMethod,
            '{{IMPORTS_FILTERS}}' => $importsFilters,
        ];

        return $this->renderTemplate($tpl, $replacements);
    }

    private function renderTemplate(string $template, array $vars): string
    {
        return strtr($template, $vars);
    }

    private function updateAppServiceProvider(string $name, string $pluralStudly): void
    {
        $provider = app_path('Providers/AppServiceProvider.php');
        if (!file_exists($provider)) { $this->warn('AppServiceProvider no encontrado, no se agrega binding'); return; }
        $content = file_get_contents($provider);
        $bindLine = "        $".'this->app->bind(\\App\\Services\\'.$pluralStudly.'\\'.$name.'ServiceInterface::class, \\App\\Services\\'.$pluralStudly.'\\'.$name.'Service::class);';
        if (str_contains($content, $bindLine)) { return; }
        if (preg_match('/public function register\(\)\s*{/', $content)) {
            $content = preg_replace('/public function register\(\)\s*{/', "$0\n".$bindLine, $content, 1);
            file_put_contents($provider, $content);
            $this->line('Binding añadido en AppServiceProvider');
        } else {
            $this->warn('No se encontró método register() en AppServiceProvider');
        }
    }

    private function updateApiFiltersConfig(string $pluralSnake): void
    {
        $configPath = config_path('api_filters.php');
        if (!file_exists($configPath)) { return; }
        $data = include $configPath;
        if (!is_array($data)) { return; }
        if (array_key_exists($pluralSnake, $data)) { return; }
        $data = [$pluralSnake => [
            'searchable' => ['name'],
            'sortable'   => ['id','created_at'],
            'includes'   => [],
            'fields'     => ['id','name','created_at'],
        ]] + $data;
        $export = var_export($data, true);
        $content = "<?php\nreturn {$export};\n";
        file_put_contents($configPath, $content);
        $this->line('Config api_filters.php actualizado');
    }

    private function updateRoutesFile(string $name, string $pluralStudly): void
    {
        $routesPath = base_path('routes/api_admin_v1.php');
        if (!file_exists($routesPath)) {
            $this->warn('Archivo de rutas api_admin_v1.php no encontrado, se omite inserción.');
            return;
        }
        $content = file_get_contents($routesPath);
        $pluralSnake = Str::snake($pluralStudly);
        $needle = "Route::apiResource('{$pluralSnake}',";
        if (str_contains($content, $needle)) {
            $this->line('[skip] ruta ya existente');
            return;
        }
        $line = "        Route::apiResource('{$pluralSnake}', \\App\\Http\\Controllers\\Api\\V1\\Admin\\{$pluralStudly}\\{$name}Controller::class);";
        if (preg_match('/^\s*\}\);/m', $content)) {
            $content = preg_replace('/^\s*\}\);/m', $line."\n    });", $content, 1);
        } else {
            $content .= "\n".$line."\n";
        }
        file_put_contents($routesPath, $content);
        $this->line('Ruta añadida a routes/api_admin_v1.php');
    }
}
