<?php
namespace Arrau\Generators\Helpers;

class PermissionManager
{
    public static function createPermissions($routePlural, $withExtendedPerms = false)
    {
        $perms = [$routePlural.'.view',$routePlural.'.create',$routePlural.'.update',$routePlural.'.delete'];
        if($withExtendedPerms){
            $perms = array_merge($perms, [$routePlural.'.restore',$routePlural.'.forceDelete']);
        }
        // Aquí iría la lógica para crear permisos usando Spatie
        return $perms;
    }
}
