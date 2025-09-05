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
        $parsed = $schemaDerived ? $schemaDerived : $this->parseFields($rawFields);
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

    // --- FUNCIONES AUXILIARES REUTILIZABLES ---
    private function parseFields(?string $raw): array
    {
        if(!$raw){
            return [
                'fields' => ['name'],
                'definitions' => [ [ 'name'=>'name', 'type'=>'string' ] ],
            ];
        }
        $parts = preg_split('/,(?=(?:[^()]*\([^()]*\))*[^()]*$)/', $raw);
        $definitions = [];
        foreach($parts as $p){
            $p = trim($p); if($p==='') continue;
            [$fname,$typeSpec] = array_pad(explode(':',$p,2),2,'string');
            $typeSpec = $typeSpec ?: 'string';
            $definitions[] = [ 'name'=>$fname, 'type'=>$typeSpec ];
        }
        return [ 'fields'=>array_map(fn($d)=>$d['name'],$definitions), 'definitions'=>$definitions ];
    }

    private function buildMigrationColumns(array $definitions): string
    {
        $lines = [];
        foreach($definitions as $def){
            $name = $def['name'];
            $type = $def['type'];
            if($name==='id') continue;
            if(str_starts_with($type,'enum')){
                if(preg_match('/enum\((.*)\)/',$type,$m)){
                    $vals = array_filter(array_map('trim', preg_split('/[|,]/',$m[1])));
                    $valsExport = "['".implode("','", $vals)."']";
                    $lines[] = "            \$table->enum('$name', $valsExport);";
                } else {
                    $lines[] = "            \$table->string('$name');";
                }
            } elseif($type==='string') $lines[] = "            \$table->string('$name');";
            elseif($type==='text') $lines[] = "            \$table->text('$name');";
            elseif(in_array($type,['integer','int'])) $lines[] = "            \$table->integer('$name');";
            elseif(in_array($type,['bigint','bigInteger'])) $lines[] = "            \$table->bigInteger('$name');";
            elseif($type==='boolean') $lines[] = "            \$table->boolean('$name')->default(false);";
            elseif($type==='date') $lines[] = "            \$table->date('$name')->nullable();";
            elseif(in_array($type,['datetime','timestamp'])) $lines[] = "            \$table->dateTime('$name')->nullable();";
            elseif(str_starts_with($type,'decimal')){
                if(preg_match('/decimal\((\d+),(\d+)\)/',$type,$m)){
                    $lines[] = "            \$table->decimal('$name', {$m[1]}, {$m[2]})->nullable();";
                } else {
                    $lines[] = "            \$table->decimal('$name', 15, 2)->nullable();";
                }
            } else {
                $lines[] = "            \$table->string('$name');";
            }
        }
        return implode("\n", $lines);
    }

    private function buildValidationRules(array $definitions): string
    {
        $rules = [];
        foreach($definitions as $def){
            $name=$def['name']; if($name==='id') continue;
            $type=$def['type'];
            $nullable = $def['nullable'] ?? false;
            $length = $def['length'] ?? null;
            $baseReq = $nullable ? 'nullable' : 'required';
            $rule='';
            if(str_starts_with($type,'enum(')){
                if(preg_match('/enum\((.*)\)/',$type,$m)){
                    $vals = array_filter(array_map('trim', preg_split('/[|,]/',$m[1])));
                    $rule = $baseReq.'|in:'.implode(',',$vals);
                }
            } elseif(in_array($type,['string','varchar'])) {
                $rule = $baseReq.'|string'.($length?"|max:$length":"");
            } elseif(in_array($type,['text','longtext','mediumtext'])) $rule=$baseReq.'|string';
            elseif(in_array($type,['integer','int','bigint','bigInteger','smallint','tinyint'])) $rule=$baseReq.'|integer';
            elseif($type==='boolean') $rule=$baseReq.'|boolean';
            elseif(in_array($type,['date'])) $rule='nullable|date';
            elseif(in_array($type,['datetime','timestamp'])) $rule='nullable|date';
            elseif(str_starts_with($type,'decimal') || in_array($type,['float','double'])) $rule='nullable|numeric';
            else $rule=$baseReq;
            $rules[] = "            '$name' => '$rule'";
        }
        if(empty($rules)) $rules[] = "            'name' => 'required|string'";
        return "[\n".implode(",\n", $rules)."\n        ]";
    }

    private function buildTransformArray(array $fields): string
    {
        $lines=[]; foreach($fields as $f){ if($f==='id') continue; $lines[] = "            '{$f}' => (string)(\$row->{$f} ?? '')"; }
        $lines = array_map(fn($l)=> $l.',', $lines);
        return implode("\n", $lines). (empty($lines)?'':"\n");
    }

    private function buildJsColumns(array $fields): string
    {
        $cols=[]; foreach($fields as $f){ if($f==='id') continue; $title=Str::title(str_replace(['_','-'],' ',$f)); $cols[]="            { data:'$f', title:'$title' },"; }
        return implode("\n", $cols). (empty($cols)?'':"\n");
    }

    private function buildFieldSnippets(array $definitions, string $camel): array
    {
        $form=[]; $show=[]; $modelVar = '$'.$camel;
        foreach($definitions as $def){
            $name=$def['name']; $type=$def['type']; if($name==='id') continue;
            $label=Str::title(str_replace(['_','-'],' ',$name));
            if(str_starts_with($type,'enum(') && preg_match('/enum\((.*)\)/',$type,$m)){
                $vals = array_filter(array_map('trim', preg_split('/[|,]/',$m[1])));
                $options=[]; foreach($vals as $v){
                    $options[] = "<option value=\"$v\" {{ old('$name', $modelVar->$name ?? '')=='$v' ? 'selected' : '' }}>$v</option>";
                }
                $optionsHtml = implode("\n    ", $options);
                $form[] = "<div class=\"mb-5\">\n  <label class=\"form-label required\">$label</label>\n  <select name=\"$name\" class=\"form-select\" required>\n    <option value=\"\">-- Seleccione --</option>\n    $optionsHtml\n  </select>\n</div>";
            } elseif(in_array($type,['text'])) {
                $form[] = "<div class=\"mb-5\">\n  <label class=\"form-label required\">$label</label>\n  <textarea name=\"$name\" class=\"form-control\" rows=\"4\" required>{{ old('$name', $modelVar->$name ?? '') }}</textarea>\n</div>";
            } elseif(str_starts_with($type,'decimal') || in_array($type,['integer','int','bigint','bigInteger'])) {
                $form[] = "<div class=\"mb-5\">\n  <label class=\"form-label\">$label</label>\n  <input type=\"number\" step=\"any\" name=\"$name\" class=\"form-control\" value=\"{{ old('$name', $modelVar->$name ?? '') }}\" />\n</div>";
            } else {
                $form[] = "<div class=\"mb-5\">\n  <label class=\"form-label required\">$label</label>\n  <input type=\"text\" name=\"$name\" class=\"form-control\" value=\"{{ old('$name', $modelVar->$name ?? '') }}\" required />\n</div>";
            }
            $show[] = "<dt class=\"col-sm-3\">$label</dt><dd class=\"col-sm-9\">{{ $modelVar->$name }}</dd>";
        }
        return [implode("\n", $form), implode("\n", $show)];
    }

    private function inspectTableSchema(string $table): ?array
    {
        try {
            $connection = DB::connection();
            $database = $connection->getDatabaseName();
            $columns = $connection->select("SELECT COLUMN_NAME name, DATA_TYPE data_type, IS_NULLABLE is_nullable, CHARACTER_MAXIMUM_LENGTH length FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$database, $table]);
            $defs = [];
            foreach($columns as $col){
                $name = $col->name;
                if(in_array($name,['id','created_at','updated_at','deleted_at'])) continue;
                $defs[] = [
                    'name' => $name,
                    'type' => strtolower($col->data_type ?? 'string'),
                    'nullable' => ($col->is_nullable ?? 'NO') === 'YES',
                    'length' => $col->length ? (int)$col->length : null,
                ];
            }
            if(empty($defs)) return null;
            return [ 'fields' => array_map(fn($d)=>$d['name'],$defs), 'definitions'=>$defs ];
        } catch(\Throwable $e){
            $this->line('No se pudo inspeccionar esquema: '.$e->getMessage());
            return null;
        }
    }
}
