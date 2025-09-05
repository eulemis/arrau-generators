<?php
namespace Arrau\Generators\Helpers;

class FieldParser
{
    public static function parse(?string $raw): array
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
}
