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
        $name         = Str::studly($this->argument('name'));
        $pluralStudly = Str::studly(Str::pluralStudly($name));
            $pluralSnake  = Str::snake(Str::pluralStudly($name));

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

        // ...all private methods from MakeApiModule.php (paths, stubs, helpers)...

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

        // ...all stub/template methods and helpers from MakeApiModule.php...
        private function serviceInterfaceStub(string $name, string $pluralStudly): string { /* ... */ }
        private function serviceStub(string $name, string $pluralStudly): string { /* ... */ }
        private function storeRequestStub(string $name, string $pluralStudly): string { /* ... */ }
        private function updateRequestStub(string $name, string $pluralStudly): string { /* ... */ }
        private function resourceStub(string $name, string $pluralStudly): string { /* ... */ }
        private function controllerStub(string $name, string $pluralStudly): string { /* ... */ }
        private function renderTemplate(string $template, array $vars): string { /* ... */ }
        private function updateAppServiceProvider(string $name, string $pluralStudly): void { /* ... */ }
        private function updateApiFiltersConfig(string $pluralSnake): void { /* ... */ }
        private function updateRoutesFile(string $name, string $pluralStudly): void { /* ... */ }
    }
