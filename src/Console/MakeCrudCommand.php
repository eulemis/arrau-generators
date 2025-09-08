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
        // 1. Inicialización de variables principales
        $name = $this->argument('name');
        $model = Str::studly($name);
        $Model = $model;
        $pluralModel = Str::pluralStudly($model);
        $kebabPlural = Str::kebab($pluralModel);
        $stubPath = __DIR__.'/../../stubs/crud';
        $TitlePlural = Str::title(str_replace('-', ' ', $kebabPlural));
        $table = Str::snake(Str::pluralStudly($name));
        $camel = Str::camel($name);

        // 2. Procesamiento de campos y generación de archivos principales
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

        // 3. Generación de archivos principales (model, controller, service, requests, vistas, js, etc.)
        // Requests (aseguramos regeneración usando StubWriter para normalizar apertura PHP)
        if($generateFormRequests){
            $requestDir = app_path('Http/Requests/Admin/'.$pluralModel);
            if(!is_dir($requestDir)) mkdir($requestDir, 0777, true);
            $storeTarget = $requestDir.'/'.$model.'StoreRequest.php';
            $updateTarget = $requestDir.'/'.$model.'UpdateRequest.php';
            StubWriter::write($stubPath.'/request_store.stub', $storeTarget, compact('Model','validationRules','pluralModel'));
            StubWriter::write($stubPath.'/request_update.stub', $updateTarget, compact('Model','validationRules','pluralModel'));
            $this->info('FormRequests Store y Update generados');
        }

        // 4. Policy
        $policyDir = app_path('Policies');
        if(!is_dir($policyDir)) mkdir($policyDir, 0777, true);
        $policyTarget = $policyDir.'/'.$model.'Policy.php';
        StubWriter::write($stubPath.'/policy.stub', $policyTarget, compact('Model'));
        $this->info('Policy generado');

        // 5. Rutas admin
        $adminRoutes = base_path('routes/admin.php');
        $webRoutes = base_path('routes/web.php');
        $routePlural = strtolower($kebabPlural);
        $routeBlock = "\n// Admin CRUD $model\nRoute::prefix('admin')->name('admin.')->group(function(){\n    Route::get('$routePlural/data', [\\App\\Http\\Controllers\\Admin\\$pluralModel\\{$model}Controller::class,'data'])->name('$routePlural.data');\n    Route::resource('$routePlural', \\App\\Http\\Controllers\\Admin\\$pluralModel\\{$model}Controller::class);\n});\n";
        if(file_exists($adminRoutes)) {
            file_put_contents($adminRoutes, $routeBlock, FILE_APPEND);
            $this->info('Rutas añadidas en admin.php');
        } else {
            file_put_contents($webRoutes, $routeBlock, FILE_APPEND);
            $this->info('Rutas añadidas en web.php');
        }

        // 6. Breadcrumbs
        $breadcrumbsFile = base_path('routes/breadcrumbs.php');
        if(file_exists($breadcrumbsFile)) {
            $bc = file_get_contents($breadcrumbsFile);
            $needle = "'$routePlural.index'";
            if(!str_contains($bc, $needle)) {
                $breadcrumbBlock = "\n// $Model breadcrumbs\n"
                    . "Breadcrumbs::for('$routePlural.index', function (BreadcrumbTrail \$trail) {\n"
                    . "    \$trail->parent('dashboard');\n"
                    . "    \$trail->push('$TitlePlural', route('admin.$routePlural.index'));\n"
                    . "});\n"
                    . "Breadcrumbs::for('$routePlural.create', function (BreadcrumbTrail \$trail) {\n"
                    . "    \$trail->parent('$routePlural.index');\n"
                    . "    \$trail->push('Crear', route('admin.$routePlural.create'));\n"
                    . "});\n"
                    . "Breadcrumbs::for('$routePlural.edit', function (BreadcrumbTrail \$trail, $Model \$item) {\n"
                    . "    \$trail->parent('$routePlural.index');\n"
                    . "    \$trail->push('Editar', route('admin.$routePlural.edit', \$item));\n"
                    . "});\n"
                    . "Breadcrumbs::for('$routePlural.show', function (BreadcrumbTrail \$trail, $Model \$item) {\n"
                    . "    \$trail->parent('$routePlural.index');\n"
                    . "    \$trail->push('Detalle', route('admin.$routePlural.show', \$item));\n"
                    . "});\n";
                // Convertir saltos de línea y escapes a PHP plano
                $breadcrumbBlock = str_replace(['\\n', '\\$'], ["\n", "$"], $breadcrumbBlock);
                file_put_contents($breadcrumbsFile, rtrim($bc) . "\n" . $breadcrumbBlock . "\n");
                $this->info('Breadcrumbs añadidos');
            } else {
                $this->line('Breadcrumbs ya existen, saltando');
            }
        }

        $this->info('CRUD generado exitosamente.');
        return 0;

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

        // 2. Controller (Admin)
        $controllerDir = app_path('Http/Controllers/Admin/'.$pluralModel);
        if(!is_dir($controllerDir)) mkdir($controllerDir, 0777, true);
        $controllerTarget = $controllerDir.'/'.$model.'Controller.php';
        StubWriter::write($stubPath.'/controller.stub', $controllerTarget, compact('Model','camel','kebab','pluralModel','camelPlural','kebabPlural','TitleSingular','TitlePlural','routePlural'));
        $this->info('Controller admin '.(file_exists($controllerTarget) ? 'sobrescrito':'creado'));

        // 3. Migration (solo si la tabla no existe)
        if(!$tableExists){
            $migrationName = date('Y_m_d_His').'_create_'.strtolower($table).'_table.php';
            $migrationTarget = database_path('migrations/'.$migrationName);
            StubWriter::write($stubPath.'/migration.stub', $migrationTarget, compact('table','migrationColumns','softDeletesMigration'));
            $this->info('Migration creada: '.$migrationName);
        }

        // 4. Service y ServiceInterface (Admin)
        if($generateService){
            $serviceDir = app_path('Services/'.$pluralModel);
            if(!is_dir($serviceDir)) mkdir($serviceDir, 0777, true);
            $serviceTarget = $serviceDir.'/'.$model.'Service.php';
            $interfaceTarget = $serviceDir.'/'.$model.'ServiceInterface.php';
            StubWriter::write($stubPath.'/service.stub', $serviceTarget, compact('model','Model','pluralModel'));
            StubWriter::write($stubPath.'/service_interface.stub', $interfaceTarget, compact('model','Model','pluralModel'));
            $this->info('Service y ServiceInterface generados');
        }

        // 5. FormRequests (Admin, Store y Update)
        if($generateFormRequests){
            $requestDir = app_path('Http/Requests/Admin/'.$pluralModel);
            if(!is_dir($requestDir)) mkdir($requestDir, 0777, true);
            $storeTarget = $requestDir.'/'.$model.'StoreRequest.php';
            $updateTarget = $requestDir.'/'.$model.'UpdateRequest.php';
            StubWriter::write($stubPath.'/request_store.stub', $storeTarget, compact('Model','validationRules','pluralModel'));
            StubWriter::write($stubPath.'/request_update.stub', $updateTarget, compact('Model','validationRules','pluralModel'));
            $this->info('FormRequests Store y Update generados');
        }

        // 6. Vistas (todas las necesarias, admin o legacy)
        if ($isLegacy) {
            $viewDir = resource_path('views/pages/crud/'.strtolower($kebabPlural));
        } else {
            $viewDir = resource_path('views/admin/'.strtolower($kebabPlural));
        }
        if(!is_dir($viewDir)) mkdir($viewDir, 0777, true);

        $viewMap = [
            'index.blade.php'   => '/views_index.stub',
            'create.blade.php'  => '/views_create.stub',
            'edit.blade.php'    => '/views_edit.stub',
            'show.blade.php'    => '/views_show.stub',
            '_form.blade.php'   => '/views_form.stub',
        ];
        foreach ($viewMap as $file => $stub) {
            $target = $viewDir . '/' . $file;
            StubWriter::write($stubPath . $stub, $target, compact('Model','model','camel','kebab','pluralModel','camelPlural','kebabPlural','TitleSingular','TitlePlural','routePlural','jsColumns','formFields','showFields'));
        }
        $this->info('Vistas CRUD generadas en ' . $viewDir);

        // 7. JS inicializador
        $jsDir = public_path('js/crud');
        if(!is_dir($jsDir)) mkdir($jsDir, 0777, true);
        $jsTarget = $jsDir.'/'.$kebabPlural.'.js';
        StubWriter::write($stubPath.'/js.stub', $jsTarget, compact('kebabPlural','routePlural','TitlePlural','jsColumns'));
        $this->info('JS inicializador generado');

        // 8. Traducciones (es y en)
        $langDirEs = resource_path('lang/es');
        $langDirEn = resource_path('lang/en');
        if(!is_dir($langDirEs)) mkdir($langDirEs, 0777, true);
        if(!is_dir($langDirEn)) mkdir($langDirEn, 0777, true);
        $translationKey = Str::snake($pluralModel);
        $fieldsLines = array_map(function($f){ return "    '$f' => '".Str::title(str_replace(['_','-'],' ',$f))."',"; }, $parsed['fields']);
        $fieldsExport = implode("\n", $fieldsLines);
        $esTarget = $langDirEs.'/'.$translationKey.'.php';
        $enTarget = $langDirEn.'/'.$translationKey.'.php';
        $esContent = "<?php\nreturn [\n    'index_title' => '$TitlePlural',\n    'singular_title' => '$TitleSingular',\n$fieldsExport\n];\n";
        $enContent = "<?php\nreturn [\n    'index_title' => '$TitlePlural',\n    'singular_title' => '$TitleSingular',\n$fieldsExport\n];\n";
        file_put_contents($esTarget, $esContent);
        file_put_contents($enTarget, $enContent);
        $this->info('Archivos de traducción generados');

        // 9. Policy
        $policyDir = app_path('Policies');
        if(!is_dir($policyDir)) mkdir($policyDir, 0777, true);
        $policyTarget = $policyDir.'/'.$model.'Policy.php';
        StubWriter::write($stubPath.'/policy.stub', $policyTarget, compact('Model'));
        $this->info('Policy generado');

        // 10. Rutas admin
        $adminRoutes = base_path('routes/admin.php');
        $webRoutes = base_path('routes/web.php');
        $routePlural = strtolower($kebabPlural);
        $routeBlock = "\n// Admin CRUD $model\nRoute::prefix('admin')->name('admin.')->group(function(){\n    Route::get('$routePlural/data', [\\App\\Http\\Controllers\\Admin\\$pluralModel\\{$model}Controller::class,'data'])->name('$routePlural.data');\n    Route::resource('$routePlural', \\App\\Http\\Controllers\\Admin\\$pluralModel\\{$model}Controller::class);\n});\n";
        if(file_exists($adminRoutes)) {
            file_put_contents($adminRoutes, $routeBlock, FILE_APPEND);
            $this->info('Rutas añadidas en admin.php');
        } else {
            file_put_contents($webRoutes, $routeBlock, FILE_APPEND);
            $this->info('Rutas añadidas en web.php');
        }

        // 11. Breadcrumbs
        $breadcrumbsFile = base_path('routes/breadcrumbs.php');
        if(file_exists($breadcrumbsFile)) {
            $bc = file_get_contents($breadcrumbsFile);
            $needle = "'$routePlural.index'";
            if(!str_contains($bc, $needle)) {
                $breadcrumbBlock = "\n// $Model breadcrumbs\nBreadcrumbs::for('$routePlural.index', function (BreadcrumbTrail \\$trail) {\n    \\$trail->parent('dashboard');\n    \\$trail->push('$TitlePlural', route('admin.$routePlural.index'));\n});\nBreadcrumbs::for('$routePlural.create', function (BreadcrumbTrail \\$trail) {\n    \\$trail->parent('$routePlural.index');\n    \\$trail->push('Crear', route('admin.$routePlural.create'));\n});\nBreadcrumbs::for('$routePlural.edit', function (BreadcrumbTrail \\$trail, $Model \\$item) {\n    \\$trail->parent('$routePlural.index');\n    \\$trail->push('Editar', route('admin.$routePlural.edit', \\$item));\n});\nBreadcrumbs::for('$routePlural.show', function (BreadcrumbTrail \\$trail, $Model \\$item) {\n    \\$trail->parent('$routePlural.index');\n    \\$trail->push('Detalle', route('admin.$routePlural.show', \\$item));\n});\n";
                file_put_contents($breadcrumbsFile, rtrim($bc)."\n".$breadcrumbBlock."\n");
                $this->info('Breadcrumbs añadidos');
            } else {
                $this->line('Breadcrumbs ya existen, saltando');
            }
        }

        $this->info('CRUD generado exitosamente.');
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
