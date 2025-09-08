<?php
namespace Arrau\Generators\Generators\Shared;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ModelGenerator
{
    public function __construct(private readonly Command $io)
    {
    }

    public function generate(string $model, bool $force = false): ?string
    {
        $introspector = new ModelIntrospector($model);
        $table = $introspector->getTableName();
        if (!$introspector->tableExists($table)) {
            $this->io->warn("Tabla '{$table}' no existe. Se omite modelo.");
            return null;
        }

        $columns = $introspector->columns($table);
        $fillable = $introspector->fillableFields($columns);
        $hasSoft = $introspector->hasSoftDeletes($columns);

        $relationsCode = $this->buildRelations($model, $table, $introspector);

        $softDeleteImport = $hasSoft ? 'use Illuminate\\Database\\Eloquent\\SoftDeletes;' : '';
        $softDeleteTraitLine = $hasSoft ? ', SoftDeletes' : '';
        $fillableArr = array_map(fn($f)=>"'{$f}'", $fillable);
        $fillableExport = '[ '.implode(', ', $fillableArr).' ]';

        $target = app_path('Models/'.$model.'.php');
        if (file_exists($target) && !$force) {
            $this->io->line("Modelo existente, no sobrescrito: {$target}");
            return $target;
        }

        $stub = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
{{softDeleteImport}}

class {{Model}} extends Model
{
    use HasFactory{{softDeleteTraitLine}};

    protected $fillable = {{fillable}};

{{relations}}
}

PHP;
        $code = strtr($stub, [
            '{{Model}}' => $model,
            '{{softDeleteImport}}' => $softDeleteImport,
            '{{softDeleteTraitLine}}' => $softDeleteTraitLine,
            '{{fillable}}' => $fillableExport,
            '{{relations}}' => $relationsCode ? "\n".$relationsCode : '',
        ]);

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $code);
        $this->io->info('Model '.(file_exists($target) ? 'sobrescrito' : 'creado'));
        return $target;
    }

    private function buildRelations(string $model, string $table, ModelIntrospector $introspector): string
    {
        $lines = [];
        $addedMethods = [];

        // belongsTo: detectar columnas *_id
        foreach ($introspector->foreignKeys($table) as $fk) {
            $relatedModel = $introspector->guessModelFromTable($fk['referenced_table']);
            $functionName = Str::camel(Str::beforeLast($fk['column'], '_id'));
            if (isset($addedMethods[$functionName])) { continue; }
            $lines[] = '    public function ' . $functionName . "()\n"
                . "    {\n"
                . '        return ' . '$this' . '->belongsTo(\\App\\Models\\' . $relatedModel . "::class, '" . $fk['column'] . "', '" . $fk['referenced_column'] . "');\n"
                . "    }\n";
            $addedMethods[$functionName] = true;
        }

        // hasOne/hasMany: si la FK entrante tiene índice único => hasOne, si no hasMany
        foreach ($introspector->incomingForeignKeys($table) as $incoming) {
            $relatedModel = $introspector->guessModelFromTable($incoming['table']);
            $isUnique = (bool)($incoming['unique'] ?? false);
            $method = $isUnique ? 'hasOne' : 'hasMany';
            $functionName = $isUnique ? Str::camel($relatedModel) : Str::camel(Str::pluralStudly($relatedModel));
            if (isset($addedMethods[$functionName])) { continue; }
            $lines[] = '    public function ' . $functionName . "()\n"
                . "    {\n"
                . '        return ' . '$this' . '->' . $method . '(\\App\\Models\\' . $relatedModel . "::class, '" . $incoming['column'] . "', 'id');\n"
                . "    }\n";
            $addedMethods[$functionName] = true;
        }

        // belongsToMany: detectar pivotes
        foreach ($introspector->pivotCandidates($table) as $pivot) {
            $otherTable = $pivot['other_table'];
            $otherModel = $introspector->guessModelFromTable($otherTable);
            $functionName = Str::camel(Str::pluralStudly($otherModel));
            $pivotTable = $pivot['pivot_table'];
            $currentFk = Str::singular($table).'_id';
            $otherFk = Str::singular($otherTable).'_id';
            if (isset($addedMethods[$functionName])) { continue; }
            $lines[] = '    public function ' . $functionName . "()\n"
                . "    {\n"
                . '        return ' . '$this' . '->belongsToMany(\\App\\Models\\' . $otherModel . "::class, '" . $pivotTable . "', '" . $currentFk . "', '" . $otherFk . "');\n"
                . "    }\n";
            $addedMethods[$functionName] = true;
        }

        // morphTo: si la tabla actual tiene pares morph {name}_type/{name}_id
        foreach ($introspector->morphPairs($table) as $pair) {
            $functionName = Str::camel($pair['name']);
            if (isset($addedMethods[$functionName])) { continue; }
            $lines[] = '    public function ' . $functionName . "()\n"
                . "    {\n"
                . '        return ' . '$this' . "->morphTo();\n"
                . "    }\n";
            $addedMethods[$functionName] = true;
        }

        // morphOne/morphMany: para tablas que referencian morfológicamente a la tabla actual
        foreach ($introspector->incomingMorphPairs($table) as $incoming) {
            $relatedModel = $introspector->guessModelFromTable($incoming['table']);
            $baseName = Str::camel($relatedModel);
            $method = $incoming['unique'] ? 'morphOne' : 'morphMany';
            $functionName = $incoming['unique'] ? $baseName : Str::camel(Str::pluralStudly($relatedModel));
            if (isset($addedMethods[$functionName])) { continue; }
            $lines[] = '    public function ' . $functionName . "()\n"
                . "    {\n"
                . '        return ' . '$this' . '->' . $method . '(\\App\\Models\\' . $relatedModel . "::class, '" . $incoming['name'] . "');\n"
                . "    }\n";
            $addedMethods[$functionName] = true;
        }

        return implode("\n", $lines);
    }
}


