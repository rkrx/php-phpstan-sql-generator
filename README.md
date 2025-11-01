# PHPStan Types From SQL

Generate PHPStan (and optionally Psalm) shape types directly from your database schema. Point the generator at a MySQL or PostgreSQL database via PDO and it produces a small PHP class whose docblock contains `@phpstan-type` (and also `@psalm-type`) definitions for every table.

Works great to keep static-analysis types in sync with your DB without hand-writing shapes.

## Requirements

- PHP 8.2+
- `ext-pdo`
- For analysis: PHPStan 1.10+ (optional) and/or Psalm (optional)


## Install

- `composer require --dev rkr/phpstan-types-from-sql`


If you want to autoload generated types under your own namespace (recommended), add an autoload-dev mapping in your project’s `composer.json`, e.g.:

- In `composer.json` → `autoload-dev.psr-4`: `{ "App\\\Types\\\": "generated/" }`
- Then run `composer dump-autoload`

## Usage

### Code examples

### MySQL Example
- This example mirrors `test-mysql.php` but with placeholder credentials.

```
<?php

use Kir\PhpstanTypesFromSql\MySQLStaticAnalyzerTypeGenerator;
use PDO;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=your_db;charset=utf8mb4',
    'your_user',
    'your_password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$gen = new MySQLStaticAnalyzerTypeGenerator(pdo: $pdo);
$contents = $gen->generate(
    namespace: 'App\\Types',     // Namespace for the generated class
    className: 'DbTypes',         // Class name to hold the docblock
    databaseName: 'your_db',      // MySQL database name
    asArray: false,               // object{...} when false, array{...} when true
    full: true                    // require all keys (true) or allow partial (false)
);

file_put_contents(__DIR__ . '/generated/DbTypes.php', $contents);
```

Notes
- MySQL generator emits both `@phpstan-type` and `@psalm-type` per table.
- `asArray=false` yields `object{...}` shapes; `asArray=true` yields `array{...}` shapes.
- `full=true` makes all keys required; `full=false` generates a partial variant (`..._Partial`).

#### PostgreSQL Example

- This example mirrors `test-postgres.php` but with placeholder credentials.

```
<?php

use Kir\PhpstanTypesFromSql\PostgresStaticAnalyzerTypeGenerator;
use PDO;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=your_db', 'your_user', 'your_password', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
]);

// Optional: fix the current schema for lookups
$pdo->exec('SET search_path TO public');

$gen = new PostgresStaticAnalyzerTypeGenerator(pdo: $pdo);

$contents = $gen->generate(
    namespace: 'App\\Types',     // Namespace for the generated class
    className: 'DbTypes',         // Class name to hold the docblock
    databaseName: 'your_db',      // Database name (defaults to current_database())
    schemaName: 'public',         // Schema name (defaults to current_schema())
    asArray: true,                // Currently generates array shapes
    full: true
);

file_put_contents(__DIR__ . '/generated/DbTypes.php', $contents);
```

Notes
- PostgreSQL generator currently emits `@phpstan-type` array shapes for each table.
- Types are inferred from `information_schema.columns` via a built-in Postgres type mapping.

#### Filtering Tables
- You can include only specific tables by adding one or more filters. The callable receives the table name and must return `true` to include it.

```
$gen->addFilter(static fn (string $table) => str_starts_with($table, 'public_'));
$gen->addFilter(static fn (string $table) => !in_array($table, ['migrations', 'audit_log'], true));
```

### What Gets Generated
- The generator writes an empty class with a large docblock. The docblock contains shape type aliases per table.
- Examples below are abbreviated from files under `generated/` in this repository.

- MySQL (object shape, excerpt from `generated/ClassName.php`):
```
/**
 * @phpstan-type TSsoRegistry object{
 *     public_token: string,
 *     private_token: string,
 *     authorized: int,
 *     payload: string,
 *     success_url: string,
 *     failure_url: string|null,
 *     expires_at: string|null,
 *     created_at: string,
 *     updated_at: string
 * }
 *
 * @psalm-type TSsoRegistry object{
 *     public_token: string,
 *     private_token: string,
 *     authorized: int,
 *     payload: string,
 *     success_url: string,
 *     failure_url: string|null,
 *     expires_at: string|null,
 *     created_at: string,
 *     updated_at: string
 * }
 */
class ClassName {}
```

- PostgreSQL (array shape, excerpt from `generated/<ClassName>.php`):

```
/**
 * @phpstan-type TTokens array{
 *     persistent: bool,
 *     id: int,
 *     user_id: int,
 *     updated_at: string,
 *     created_at: string,
 *     expires_at: string|null,
 *     name: string|null,
 *     action: string,
 *     token: string
 * }
 */
class ClassName {}
```

These aliases can be referenced in PHPDoc throughout your codebase, for example:

```php
    // ...
    
    /** @var TTokens $row */
    private readonly array $row;
    
    // ...
    
    /** 
     * @param TSsoRegistry $record 
     */
    public function someMethod(array $record): void {
        // ...
    }
```


## Using with PHPStan

- Ensure the generated class is autoloadable (e.g., via `autoload-dev` mapping) so PHPStan can load the docblock definitions during analysis.
- You don’t need to reference the class directly; it’s sufficient that the file is autoloaded when PHPStan runs.

## Tips
 
- Regenerate after schema changes and commit the generated PHP file if you want reproducible analysis in CI.
- Unknown or uncommon SQL types will raise an exception; extend or adjust your schema/types if needed.
- MySQL enums and sets are translated into string literal unions.

## License

- MIT
