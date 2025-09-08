<?php
namespace Arrau\Generators\Generators\Api\Operations;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RoutesUpdater
{
    public function __construct(private readonly Command $io)
    {
    }

    public function addApiResource(string $model, string $pluralStudly): void
    {
        $routesPath = base_path('routes/api_admin_v1.php');
        if (!file_exists($routesPath)) {
            $this->io->warn('Archivo de rutas api_admin_v1.php no encontrado, se omite inserción.');
            return;
        }
        $content = file_get_contents($routesPath);
        $pluralSnake = Str::snake($pluralStudly);
        $needle = "Route::apiResource('{$pluralSnake}',";
        if (str_contains($content, $needle)) {
            $this->io->line('[skip] ruta ya existente');
            return;
        }
        $line = "        Route::apiResource('{$pluralSnake}', \\App\\Http\\Controllers\\Api\\V1\\Admin\\{$pluralStudly}\\{$model}Controller::class);";
        if (preg_match('/^\s*\}\);/m', $content)) {
            $content = preg_replace('/^\s*\}\);/m', $line."\n    });", $content, 1);
        } else {
            $content .= "\n".$line."\n";
        }
        file_put_contents($routesPath, $content);
        $this->io->line('Ruta añadida a routes/api_admin_v1.php');
    }
}


