<?php
namespace Arrau\Generators\Generators\Api\Operations;

use Illuminate\Console\Command;

class ApiFiltersConfigurator
{
    public function __construct(private readonly Command $io)
    {
    }

    public function ensureResourceConfig(string $pluralSnake): void
    {
        $configPath = config_path('api_filters.php');
        if (!file_exists($configPath)) { return; }
        $data = include $configPath;
        if (!is_array($data)) { return; }
        if (array_key_exists($pluralSnake, $data)) { return; }
        $data = [$pluralSnake => [
            'searchable' => ['name'],
            'sortable'   => ['id','created_at'],
            'includes'   => [],
            'fields'     => ['id','name','created_at'],
        ]] + $data;
        $export = var_export($data, true);
        $content = "<?php\nreturn {$export};\n";
        file_put_contents($configPath, $content);
        $this->io->line('Config api_filters.php actualizado');
    }
}


