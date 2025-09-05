<?php
namespace Arrau\Generators;

use Illuminate\Support\ServiceProvider;
use Arrau\Generators\Console\MakeCrudCommand;
use Arrau\Generators\Console\MakeApiModuleCommand;

class GeneratorsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            MakeCrudCommand::class,
            MakeApiModuleCommand::class,
        ]);
    }
}
