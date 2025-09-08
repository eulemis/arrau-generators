<?php
namespace Arrau\Generators\Generators\Api\Operations;

use Illuminate\Console\Command;

class AppServiceProviderBinder
{
    public function __construct(private readonly Command $io)
    {
    }

    public function addBinding(string $model, string $pluralStudly): void
    {
        $provider = app_path('Providers/AppServiceProvider.php');
        if (!file_exists($provider)) { $this->io->warn('AppServiceProvider no encontrado, no se agrega binding'); return; }
        $content = file_get_contents($provider);
        $bindLine = "        $".'this->app->bind(\\App\\Services\\'.$pluralStudly.'\\'.$model.'ServiceInterface::class, \\App\\Services\\'.$pluralStudly.'\\'.$model.'Service::class);';
        if (str_contains($content, $bindLine)) { return; }
        if (preg_match('/public function register\(\)\s*{/', $content)) {
            $content = preg_replace('/public function register\(\)\s*{/', "$0\n".$bindLine, $content, 1);
            file_put_contents($provider, $content);
            $this->io->line('Binding añadido en AppServiceProvider');
        } else {
            $this->io->warn('No se encontró método register() en AppServiceProvider');
        }
    }
}


