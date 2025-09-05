<?php
namespace Arrau\Generators\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Arrau\Generators\Helpers\StubWriter;
use Arrau\Generators\Helpers\FieldParser;
use Arrau\Generators\Helpers\PermissionManager;

class MakeCrudCommand extends Command
{
    protected $signature = 'make:crud {name} {--fields=} {--softdelete} {--legacy} {--no-service} {--no-form-requests} {--no-permissions} {--assign-permissions-to=} {--assign-permissions-user=} {--with-extended-perms} {--force}';
    protected $description = 'Genera CRUD (Model, Migration, Controller, Vistas, Rutas) con modo admin o legacy.';

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $model = $name;
        $Model = $model;
        $camel = Str::camel($model);
        $kebab = Str::kebab($model);
        $pluralModel = Str::pluralStudly($model);
        $camelPlural = Str::camel($pluralModel);
        $kebabPlural = Str::kebab($pluralModel);
        $table = Str::snake($pluralModel);
        $TitleSingular = Str::title(Str::snake($model,' '));
        $TitlePlural = Str::title(Str::snake($pluralModel,' '));
        $routePlural = Str::kebab($pluralModel);

        $stubPath = __DIR__.'/../../stubs/crud';
        if(!is_dir($stubPath)){
            $this->error('No existen stubs en '.$stubPath);
            return 1;
        }

        $rawFields = $this->option('fields');
        $tableExists = Schema::hasTable($table);
        $schemaDerived = null;
        if($tableExists && !$rawFields){
            $schemaDerived = $this->inspectTableSchema($table);
            if(!empty($schemaDerived['fields'])){
                $this->info('Tabla existente detectada; reglas derivadas desde esquema.');
            }
        }
        $parsed = $schemaDerived ? $schemaDerived : FieldParser::parse($rawFields);
        $fillableArr = array_map(fn($f) => "'$f'", $parsed['fields']);
        $fillable = '[ '.implode(', ', $fillableArr).' ]';

        $useSoft = (bool)$this->option('softdelete');
        $softDeleteImport = $useSoft ? 'use Illuminate\\Database\\Eloquent\\SoftDeletes;' : '';
        $softDeleteTraitLine = $useSoft ? ', SoftDeletes' : '';
        $softDeletesMigration = $useSoft ? '            $table->softDeletes();' : '';

        $migrationColumns = $this->buildMigrationColumns($parsed['definitions']);
        $validationRules = $this->buildValidationRules($parsed['definitions']);
        $transformArray = $this->buildTransformArray($parsed['fields']);
        $jsColumns = $this->buildJsColumns($parsed['fields']);
        [$formFields, $showFields] = $this->buildFieldSnippets($parsed['definitions'], $camel);

        $isLegacy = (bool)$this->option('legacy');
        $force = (bool)$this->option('force');
        $generateService = !$this->option('no-service');
        $generateFormRequests = !$this->option('no-form-requests');
        $generatePermissions = !$this->option('no-permissions');
        $assignRole = $this->option('assign-permissions-to');
        $assignUsersRaw = $this->option('assign-permissions-user');
        $assignUserIds = $assignUsersRaw ? array_filter(array_map('trim', explode(',', $assignUsersRaw))) : [];
        $withExtendedPerms = (bool)$this->option('with-extended-perms');
        $adminRoutePrefix = $isLegacy ? '' : 'admin.';

        // 1. Model
        $modelTarget = app_path('Models/'.$model.'.php');
        $modelExists = file_exists($modelTarget);
        if(!$modelExists || $force){
            StubWriter::write($stubPath.'/model.stub', $modelTarget, compact('Model','fillable','softDeleteImport','softDeleteTraitLine'));
            $this->info('Model '.($modelExists ? 'sobrescrito':'creado'));
        } else {
            $this->line('Model ya existe (usa --force para sobrescribir)');
            if($useSoft){
                $contents = file_get_contents($modelTarget);
                if(!str_contains($contents,'SoftDeletes')){
                    if(!str_contains($contents,'use Illuminate\\Database\\Eloquent\\SoftDeletes;')){
                        $contents = preg_replace('/namespace App\\Models;/', "namespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;", $contents,1);
                    }
                    $contents = preg_replace('/use HasFactory;/', 'use HasFactory, SoftDeletes;', $contents,1);
                    file_put_contents($modelTarget,$contents);
                    $this->info('SoftDeletes agregado a modelo existente');
                }
            }
        }
        // ...continúa la lógica migrada...
        return 0;
    }
}
