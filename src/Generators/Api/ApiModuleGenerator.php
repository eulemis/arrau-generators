<?php
namespace Arrau\Generators\Generators\Api;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Arrau\Generators\Helpers\StubWriter;
use Arrau\Generators\Generators\Api\Support\DocBlockBuilder;
use Arrau\Generators\Generators\Api\Operations\AppServiceProviderBinder;
use Arrau\Generators\Generators\Api\Operations\ApiFiltersConfigurator;
use Arrau\Generators\Generators\Api\Operations\RoutesUpdater;
use Arrau\Generators\Generators\Shared\ModelGenerator;

class ApiModuleGenerator
{
    public function __construct(private readonly Command $io)
    {
    }

    public function generate(string $name, array $options = []): int
    {
        $model = Str::studly($name);
        $pluralStudly = Str::studly(Str::pluralStudly($model));
        $pluralSnake = Str::snake($pluralStudly);

        $force = (bool)($options['force'] ?? false);
        $withBind = !($options['no-bind'] ?? false);
        $withFilters = !($options['no-filters'] ?? false);
        $withRoute = !($options['no-route'] ?? false);
        $withDocs = !($options['no-docs'] ?? false);
        $withModel = (bool)($options['with-model'] ?? false);

        $paths = $this->paths($model, $pluralStudly);

        foreach ($paths as $targetPath) {
            if (file_exists($targetPath) && !$force) {
                $this->io->error("Existe: {$targetPath} (usa --force para sobrescribir)");
                return Command::FAILURE;
            }
        }

        foreach ($paths as $targetPath) {
            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        // Build controller variable placeholders
        $doc = new DocBlockBuilder($pluralStudly, $model, $withDocs, $withFilters);
        [$importsFilters, $filtersMethod] = $doc->buildFiltersSection($pluralStudly);
        [$docGroup, $docIndex, $docStore, $docShow, $docUpdate, $docDestroy] = $doc->buildDocs($pluralStudly, $model);

        $common = [
            'PLURAL' => $pluralStudly,
            'PLURAL_SNAKE' => $pluralSnake,
            'MODEL' => $model,
        ];

        $controllerVars = $common + [
            'IMPORTS_FILTERS' => $importsFilters,
            'FILTERS_METHOD' => $filtersMethod,
            'DOC_GROUP' => $docGroup,
            'DOC_INDEX' => $docIndex,
            'DOC_STORE' => $docStore,
            'DOC_SHOW' => $docShow,
            'DOC_UPDATE' => $docUpdate,
            'DOC_DESTROY' => $docDestroy,
        ];

        $stubPath = __DIR__.'/../../../stubs/api';

        // Write files from stubs
        $map = [
            $stubPath.'/service_interface.stub' => $paths['serviceInterface'],
            $stubPath.'/service.stub' => $paths['service'],
            $stubPath.'/request_store.stub' => $paths['requestStore'],
            $stubPath.'/request_update.stub' => $paths['requestUpdate'],
            $stubPath.'/resource.stub' => $paths['resource'],
            $stubPath.'/controller.stub' => $paths['controller'],
        ];

        foreach ($map as $stub => $target) {
            $vars = str_ends_with($stub, 'controller.stub') ? $controllerVars : $common;
            StubWriter::write($stub, $target, $vars);
            $this->io->line('Creado: '.$target);
        }

        // Model opcional derivado del esquema existente
        if ($withModel) {
            (new ModelGenerator($this->io))->generate($model, $force);
        }

        // Bindings
        if ($withBind) {
            (new AppServiceProviderBinder($this->io))->addBinding($model, $pluralStudly);
        } else {
            $this->io->line('[skip] binding (--no-bind)');
        }

        // Filters config
        if ($withFilters) {
            (new ApiFiltersConfigurator($this->io))->ensureResourceConfig($pluralSnake);
        } else {
            $this->io->line('[skip] filtros (--no-filters)');
        }

        // Routes
        if ($withRoute) {
            (new RoutesUpdater($this->io))->addApiResource($model, $pluralStudly);
        } else {
            $this->io->line('[skip] ruta (--no-route)');
        }

        $this->io->info('Estructura API generada');
        if (!$withRoute) {
            $this->io->line("Ruta sugerida (routes/api_admin_v1.php):\nRoute::apiResource('{$pluralSnake}', \\App\\Http\\Controllers\\Api\\V1\\Admin\\{$pluralStudly}\\{$model}Controller::class);");
        }
        $this->io->warn("Recuerda crear migración y modelo App\\Models\\{$model} si no existe.");

        return Command::SUCCESS;
    }

    private function paths(string $model, string $pluralStudly): array
    {
        return [
            'serviceInterface' => app_path("Services/{$pluralStudly}/{$model}ServiceInterface.php"),
            'service' => app_path("Services/{$pluralStudly}/{$model}Service.php"),
            'requestStore' => app_path("Http/Requests/Admin/{$pluralStudly}/{$model}StoreRequest.php"),
            'requestUpdate' => app_path("Http/Requests/Admin/{$pluralStudly}/{$model}UpdateRequest.php"),
            'resource' => app_path("Http/Resources/Admin/{$pluralStudly}/{$model}Resource.php"),
            'controller' => app_path("Http/Controllers/Api/V1/Admin/{$pluralStudly}/{$model}Controller.php"),
        ];
    }
}


