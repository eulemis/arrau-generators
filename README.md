# arrau-generators

Generadores CRUD y API para Laravel.

## Instalación local

1. Clona o copia la carpeta `packages/arrau-generators` en tu proyecto.
2. Agrega en tu `composer.json` principal:

```json
"repositories": [
  {
    "type": "path",
  "url": "./packages/arrau-generators"
  }
]
```

3. Instala el paquete:

```
composer require arrau/generators:dev-main
```

4. Agrega el ServiceProvider en `config/app.php` si no se autodetecta:

```php
Arrau\Generators\GeneratorsServiceProvider::class,
```

## Uso

- `php artisan make:crud Nombre --fields="campo1:string,campo2:int"`
- `php artisan make:api-module Nombre --fields="campo1:string,campo2:int"`

## Personalización

- Modifica los archivos stub en `packages/arrau-generators/stubs/crud/` y `stubs/api/` para cambiar la estructura generada.
- Extiende los helpers en `src/Helpers/` para lógica personalizada.

## Publicación en GitHub

1. Inicializa un repo en la carpeta del paquete:
   ```
  cd packages/arrau-generators
   git init
   git add .
   git commit -m "Initial commit"
  git remote add origin https://github.com/tuusuario/arrau-generators.git
   git push -u origin main
   ```
2. Puedes instalarlo en otros proyectos usando la sección `repositories` y `composer require` como arriba.
