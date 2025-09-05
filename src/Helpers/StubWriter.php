<?php
namespace Arrau\Generators\Helpers;

class StubWriter
{
    public static function write($stubFile, $target, array $vars)
    {
        $contents = file_get_contents($stubFile);
        foreach ($vars as $k => $v) {
            $contents = str_replace('{{' . $k . '}}', $v, $contents);
        }
        file_put_contents($target, $contents);
    }
}
