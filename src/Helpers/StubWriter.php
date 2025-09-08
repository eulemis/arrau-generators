<?php
namespace Arrau\Generators\Helpers;

class StubWriter
{
    public static function write($stubFile, $target, array $vars)
    {
        $contents = file_get_contents($stubFile);
        foreach ($vars as $k => $v) {
            // Reemplazo tolerante a espacios en el placeholder
            $pattern = '/\{\{\s*' . preg_quote($k, '/') . '\s*\}\}/';
            $contents = preg_replace($pattern, $v, $contents);
        }
        // Fallbacks comunes por compatibilidad de mayúsculas en stubs antiguos
        if (isset($vars['pluralModel'])) {
            $contents = preg_replace('/\{\{\s*PLURAL_MODEL\s*\}\}/', $vars['pluralModel'], $contents);
        }
        // Normalizar: eliminar BOM y espacios/líneas antes de la etiqueta de apertura
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $contents = preg_replace('/^\s*(<\?php)/', '<?php', $contents, 1);
        // Quitar línea en blanco inmediatamente después de <?php (dejar solo una nueva línea)
        $contents = preg_replace('/^<\?php(\r?\n)\r?\n/', '<?php$1', $contents, 1);
        file_put_contents($target, $contents);
    }
}
