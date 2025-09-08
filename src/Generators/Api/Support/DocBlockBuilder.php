<?php
namespace Arrau\Generators\Generators\Api\Support;

use Illuminate\Support\Str;

class DocBlockBuilder
{
    public function __construct(
        private readonly string $pluralStudly,
        private readonly string $model,
        private readonly bool $withDocs,
        private readonly bool $withFilters
    ) {}

    public function buildFiltersSection(string $pluralStudly): array
    {
        if (!$this->withFilters) {
            return ['', ''];
        }
        $pluralSnake = Str::snake($pluralStudly);
        $imports = "use App\\Api\\Filtering\\{$pluralStudly}\\SearchFilter;\n"
                 . "use App\\Api\\Filtering\\{$pluralStudly}\\SortFilter;\n"
                 . "use App\\Api\\Filtering\\Common\\IncludeFilter;\n";
        $method = <<<'FILT'
    protected function filters(): array
    {
        $cfg = config('api_filters.{{PLURAL_SNAKE}}', []);
        return [
            'search'  => new SearchFilter($cfg['searchable'] ?? []),
            'sort'    => new SortFilter($cfg['sortable'] ?? []),
            'include' => new IncludeFilter($cfg['includes'] ?? []),
            'fields'  => new \App\Api\Filtering\Common\FieldFilter($cfg['fields'] ?? []),
        ];
    }

FILT;
        $method = strtr($method, ['{{PLURAL_SNAKE}}' => $pluralSnake]);
        return [$imports, $method];
    }

    public function buildDocs(string $pluralStudly, string $model): array
    {
        if (!$this->withDocs) {
            return ['', '', '', '', '', ''];
        }
        $groupName = Str::headline($pluralStudly);
        $docGroup = "/**\n * @group Admin {$groupName}\n * Endpoints de {$groupName}.\n */";
        $docIndex = <<<'DOC'
    /**
     * Listar recursos.
     * @authenticated
     * @queryParam page integer Página actual. Example: 1
     * @queryParam per_page integer Elementos por página (1-100). Example: 15
     * @queryParam search string Búsqueda parcial. Example: demo
     * @queryParam sort string Campo de orden (prefijo - para DESC). Example: -created_at
     * @queryParam include string Relaciones a incluir separadas por coma. Example: 
     * @queryParam fields string Subconjunto de campos a devolver. Example: id,name,created_at
     * @response 200 scenario=success {"success":true,"data":[],"meta":{"current_page":1,"per_page":15}}
     */
    // index heredado de AbstractResourceController
DOC;
        $docStore = <<<'DOC'
    /**
     * Crear {{MODEL}}.
     * @authenticated
     * @bodyParam name string required Nombre.
     * @response 201 {"success":true,"message":"Created","data":{"id":1}}
     * @response 422 {"success":false,"message":"Validation error"}
     */
DOC;
        $docShow = <<<'DOC'
    /**
     * Mostrar un {{MODEL}}.
     * @authenticated
     * @response 200 {"success":true,"data":{"id":1}}
     * @response 404 {"success":false,"message":"Not Found"}
     */
DOC;
        $docUpdate = <<<'DOC'
    /**
     * Actualizar {{MODEL}}.
     * @authenticated
     * @bodyParam name string Nombre.
     * @response 200 {"success":true,"message":"Updated","data":{"id":1}}
     * @response 404 {"success":false,"message":"Not Found"}
     * @response 422 {"success":false,"message":"Validation error"}
     */
DOC;
        $docDestroy = <<<'DOC'
    /**
     * Eliminar {{MODEL}}.
     * @authenticated
     * @response 200 {"success":true,"message":"Deleted"}
     * @response 404 {"success":false,"message":"Not Found"}
     */
DOC;

        $repl = ['{{MODEL}}' => $model];
        return [
            $docGroup,
            $docIndex, 
            strtr($docStore, $repl),
            strtr($docShow, $repl),
            strtr($docUpdate, $repl),
            strtr($docDestroy, $repl),
        ];
    }
}


