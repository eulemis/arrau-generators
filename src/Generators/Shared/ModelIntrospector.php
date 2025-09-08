<?php
namespace Arrau\Generators\Generators\Shared;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ModelIntrospector
{
    public function __construct(private readonly string $modelClassName)
    {
    }

    public function getTableName(): string
    {
        return Str::snake(Str::pluralStudly($this->modelClassName));
    }

    public function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    public function columns(string $table): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            "SELECT COLUMN_NAME name, DATA_TYPE data_type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
            [$database, $table]
        );
        return array_map(fn($r) => [ 'name' => $r->name, 'data_type' => strtolower($r->data_type ?? '') ], $rows);
    }

    public function hasSoftDeletes(array $columns): bool
    {
        return in_array('deleted_at', array_map(fn($c)=>$c['name'], $columns), true);
    }

    public function hasTimestamps(array $columns): bool
    {
        $names = array_map(fn($c)=>$c['name'], $columns);
        return in_array('created_at', $names, true) && in_array('updated_at', $names, true);
    }

    public function fillableFields(array $columns): array
    {
        $ignore = ['id','created_at','updated_at','deleted_at'];
        return array_values(array_filter(array_map(fn($c)=>$c['name'], $columns), fn($n)=>!in_array($n,$ignore,true)));
    }

    public function foreignKeys(string $table): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            "SELECT kcu.TABLE_NAME table_name, kcu.COLUMN_NAME column_name, kcu.REFERENCED_TABLE_NAME referenced_table, kcu.REFERENCED_COLUMN_NAME referenced_column
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL",
            [$database, $table]
        );
        return array_map(fn($r) => [
            'column' => $r->column_name,
            'referenced_table' => $r->referenced_table,
            'referenced_column' => $r->referenced_column,
        ], $rows);
    }

    public function incomingForeignKeys(string $table): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            "SELECT kcu.TABLE_NAME table_name, kcu.COLUMN_NAME column_name
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME = ?",
            [$database, $table]
        );
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'table' => $r->table_name,
                'column' => $r->column_name,
                // marcar si en la tabla externa hay un índice único sobre esa columna FK
                'unique' => $this->hasUniqueIndex($r->table_name, [$r->column_name])
            ];
        }
        return $result;
    }

    public function guessModelFromTable(string $table): string
    {
        return Str::studly(Str::singular(str_replace(['-', ' '], '_', $table)));
    }

    /**
     * Detecta tablas pivote MANY-TO-MANY para la tabla dada.
     * Heurística: una tabla cuyo nombre sea {singularA}_{singularB} (orden alfabético)
     * y que contenga exactamente dos claves foráneas a las tablas A y B.
     */
    public function pivotCandidates(string $table): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $allTables = $this->allTables($database);

        $pivots = [];
        $singular = Str::singular($table);
        foreach ($allTables as $tb) {
            $parts = explode('_', $tb);
            if (count($parts) !== 2) continue;
            $a = $parts[0];
            $b = $parts[1];
            $ordered = [min($a,$b), max($a,$b)];
            if ($tb !== implode('_', $ordered)) continue; // debe estar ordenado alfabéticamente
            if (!Schema::hasTable($tb)) continue;
            $fks = $this->foreignKeys($tb);
            if (count($fks) !== 2) continue;
            // comprobar que hace referencia a la tabla actual y otra
            $refTables = array_values(array_unique(array_map(fn($fk)=>$fk['referenced_table'],$fks)));
            if (count($refTables) !== 2) continue;
            if (!in_array($table, $refTables, true)) continue;
            $other = $refTables[0] === $table ? $refTables[1] : $refTables[0];
            $pivots[] = [ 'pivot_table' => $tb, 'other_table' => $other ];
        }
        return $pivots;
    }

    protected function allTables(string $database): array
    {
        $rows = DB::connection()->select(
            "SELECT TABLE_NAME table_name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?",
            [$database]
        );
        return array_map(fn($r)=>$r->table_name, $rows);
    }

    /**
     * Pares morfológicos locales en una tabla: detecta {base}_type y {base}_id.
     * Retorna lista: [ ['name'=>base, 'type_column'=>..., 'id_column'=>...] ]
     */
    public function morphPairs(string $table): array
    {
        $cols = array_map(fn($c)=>$c['name'], $this->columns($table));
        $pairs = [];
        foreach ($cols as $col) {
            if (!str_ends_with($col, '_id')) continue;
            $base = substr($col, 0, -3);
            $typeCol = $base.'_type';
            if (in_array($typeCol, $cols, true)) {
                $pairs[] = [ 'name' => $base, 'type_column' => $typeCol, 'id_column' => $col ];
            }
        }
        return $pairs;
    }

    /**
     * Pares morfológicos entrantes: tablas que contienen {base}_type/{base}_id
     * Retorna lista: [ ['table'=>other_table, 'name'=>base] ]
     */
    public function incomingMorphPairs(string $table): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $acc = [];
        foreach ($this->allTables($database) as $tb) {
            if ($tb === $table) continue;
            if (!Schema::hasTable($tb)) continue;
            foreach ($this->morphPairs($tb) as $pair) {
                $unique = $this->hasUniqueIndex($tb, [$pair['type_column'], $pair['id_column']]);
                $acc[] = [ 'table' => $tb, 'name' => $pair['name'], 'unique' => $unique ];
            }
        }
        return $acc;
    }

    /**
     * Verifica si existe un índice único que cubra exactamente las columnas dadas (orden indiferente).
     */
    public function hasUniqueIndex(string $table, array $columns): bool
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            "SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$database, $table]
        );
        // Agrupar columnas por índice
        $indexToCols = [];
        $uniqueIndexes = [];
        foreach ($rows as $r) {
            $idx = $r->INDEX_NAME;
            $indexToCols[$idx] = $indexToCols[$idx] ?? [];
            $indexToCols[$idx][(int)$r->SEQ_IN_INDEX] = $r->COLUMN_NAME;
            if ((int)$r->NON_UNIQUE === 0) {
                $uniqueIndexes[$idx] = true;
            }
        }
        $target = array_values($columns);
        sort($target);
        foreach ($indexToCols as $idx => $cols) {
            if (!isset($uniqueIndexes[$idx])) continue;
            ksort($cols);
            $arr = array_values($cols);
            sort($arr);
            if ($arr === $target) {
                return true;
            }
        }
        return false;
    }
}


