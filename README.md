# TimeFrontiers PHP Database Object

Database Object trait for Active Record pattern with query builder support.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Installation

```bash
composer require timefrontiers/php-database-object
```

## Requirements

- PHP 8.1+
- `timefrontiers/php-sql-database` ^1.0
- `timefrontiers/php-has-errors` ^1.0

## Quick Start

```php
use TimeFrontiers\Helper\DatabaseObject;
use TimeFrontiers\Helper\HasErrors;

class User {
  use DatabaseObject, HasErrors;

  protected static string $_db_name = 'myapp';
  protected static string $_table_name = 'users';
  protected static string $_primary_key = 'id';
  protected static array $_db_fields = []; // Auto-loaded from schema

  public int $id;
  public string $name;
  public string $email;
  public string $status = 'active';
  protected ?string $_created = null;
  protected ?string $_updated = null;
  protected ?string $_author = null;
}

// Find by ID
$user = User::findById(123);

// Query builder
$activeUsers = User::query()
  ->where('status', 'active')
  ->orderBy('name')
  ->limit(10)
  ->get();

// Create
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->save();

// Update
$user->name = 'Jane Doe';
$user->save();

// Delete
$user->delete();
```

## Connection Management

Three levels of connection resolution:

```php
// 1. Instance-level (highest priority)
$user = new User();
$user->setConnection($conn);

// 2. Class-level
User::useConnection($conn);

// 3. Global fallback (lowest priority)
global $database;
$database = new SQLDatabase(...);
```

## Required Static Properties

```php
class MyEntity {
  use DatabaseObject;

  // Required
  protected static string $_db_name = 'database_name';
  protected static string $_table_name = 'table_name';
  protected static string $_primary_key = 'id';

  // Optional (auto-loaded from INFORMATION_SCHEMA if empty)
  protected static array $_db_fields = [];
}
```

## Query Builder

### Basic Queries

```php
// Find all
$users = User::findAll();

// Find by ID
$user = User::findById(123);

// Count
$count = User::countAll();

// Check existence
$exists = User::valueExists('email', 'john@example.com');
```

### Fluent Queries

```php
// WHERE conditions
User::query()
  ->where('status', 'active')           // status = 'active'
  ->where('age', '>', 18)               // age > 18
  ->where('role', '!=', 'admin')        // role != 'admin'
  ->get();

// OR conditions
User::query()
  ->where('status', 'active')
  ->orWhere('role', 'admin')
  ->get();

// IN / NOT IN
User::query()
  ->whereIn('status', ['active', 'pending'])
  ->whereNotIn('role', ['banned', 'suspended'])
  ->get();

// NULL checks
User::query()
  ->whereNull('deleted_at')
  ->whereNotNull('verified_at')
  ->get();

// Ordering
User::query()
  ->orderBy('name')
  ->orderByDesc('created_at')
  ->get();

// Pagination
User::query()
  ->limit(10)
  ->offset(20)
  ->get();

// Or use take()
User::query()->take(10, 20)->get();

// First result
$user = User::query()
  ->where('email', 'john@example.com')
  ->first();

// Count matching
$count = User::query()
  ->where('status', 'active')
  ->count();

// Check existence
$exists = User::query()
  ->where('email', 'john@example.com')
  ->exists();
```

### Custom SQL

Use placeholders for database/table names:

```php
$users = User::findBySql(
  "SELECT * FROM :database:.:table:
   WHERE status = ? AND created_at > ?
   ORDER BY :primary_key: DESC",
  ['active', '2024-01-01']
);
```

| Placeholder | Replaced With |
|-------------|---------------|
| `:database:` or `:db:` | `$_db_name` |
| `:table:` or `:tbl:` | `$_table_name` |
| `:primary_key:` or `:pkey:` | `$_primary_key` |

## CRUD Operations

### Create

```php
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';

if ($user->save()) {
  echo "Created with ID: {$user->id}";
} else {
  $errors = $user->getErrors();
}
```

### Update

```php
$user = User::findById(123);
$user->name = 'Jane Doe';

if (!$user->save()) {
  $errors = $user->getErrors();
}
```

### Delete

```php
$user = User::findById(123);

if (!$user->delete()) {
  $errors = $user->getErrors();
}
```

## Timestamps & Author

The trait automatically handles these fields if they exist:

| Property | Behavior |
|----------|----------|
| `$_created` | Set to current datetime on insert |
| `$_updated` | Set to current datetime on insert/update |
| `$_author` | Set from `$session->name` on insert |

```php
class Post {
  use DatabaseObject;

  protected ?string $_created = null;
  protected ?string $_updated = null;
  protected ?string $_author = null;

  // Accessors
  public function created():?string { ... }  // Built-in
  public function updated():?string { ... }  // Built-in
  public function author():?string { ... }   // Built-in
}

$post = new Post();
$post->title = 'Hello';
$post->save();

echo $post->created();  // "2024-01-15 10:30:00"
echo $post->author();   // "john_doe" (from $session->name)
```

## Empty Properties

By default, empty values are skipped during save. Use `$empty_props` to allow specific fields to be empty.

The trait declares `public array $empty_props = []` with an empty default. PHP 8 rejects a class-level redeclaration whose default differs from the trait's (`Fatal error: ... definition differs and is considered incompatible`), so consumers should not redeclare the property — assign the whitelist in the constructor instead:

```php
class Article {
  use DatabaseObject;

  public string $title;
  public string $subtitle = '';              // Can be saved as empty string
  public ?string $meta_description = null;   // Can be saved as NULL

  public function __construct() {
    $this->empty_props = ['subtitle', 'meta_description'];
  }
}
```

## Schema Caching

Field information is cached from `INFORMATION_SCHEMA` to avoid repeated queries:

```php
use TimeFrontiers\Database\Schema\TableSchema;

// Clear cache for a specific table
TableSchema::clearCache('myapp', 'users');

// Clear cache for entire database
TableSchema::clearCache('myapp');

// Clear all cached schemas
TableSchema::clearCache();
```

## Error Handling

The trait uses `HasErrors` for error management:

```php
$user = new User();
$user->email = 'invalid';

if (!$user->save()) {
  // Get all errors
  $errors = $user->getErrors();

  // Check specific context
  if ($user->hasErrors('_create')) {
    // Handle creation errors
  }

  // Get first error message
  $message = $user->firstError('_create');

  // Use with InstanceError for rank-based filtering
  $visibleErrors = (new InstanceError($user))->get('_create');
}
```

### Error Contexts

| Context | Triggered By |
|---------|--------------|
| `_create` | Insert failures |
| `_update` | Update failures |
| `_delete` | Delete failures |

## Static Accessors

```php
User::primaryKey();    // "id"
User::tableName();     // "users"
User::databaseName();  // "myapp"
User::tableFields();   // ["id", "name", "email", ...]
```

## Complete Example

```php
use TimeFrontiers\Helper\DatabaseObject;
use TimeFrontiers\Helper\HasErrors;
use TimeFrontiers\SQLDatabase;

class Product {
  use DatabaseObject, HasErrors;

  protected static string $_db_name = 'store';
  protected static string $_table_name = 'products';
  protected static string $_primary_key = 'id';
  protected static array $_db_fields = [];

  public int $id;
  public string $name;
  public string $sku;
  public float $price;
  public int $stock = 0;
  public string $status = 'draft';
  protected ?string $_created = null;
  protected ?string $_updated = null;
  protected ?string $_author = null;

  public ?string $description = null;

  public function __construct() {
    $this->empty_props = ['description'];  // see "Empty Properties" above
  }

  /**
   * Publish the product.
   */
  public function publish():bool {
    if ($this->stock <= 0) {
      $this->_userError('publish', 'Cannot publish product with no stock');
      return false;
    }

    $this->status = 'published';
    return $this->save();
  }

  /**
   * Find products low on stock.
   */
  public static function findLowStock(int $threshold = 10):array {
    return static::query()
      ->where('status', 'published')
      ->where('stock', '<=', $threshold)
      ->orderBy('stock')
      ->get();
  }
}

// Usage
Product::useConnection($database);

// Create
$product = new Product();
$product->name = 'Widget';
$product->sku = 'WDG-001';
$product->price = 29.99;
$product->stock = 100;

if ($product->save()) {
  $product->publish();
}

// Query
$lowStock = Product::findLowStock(5);

foreach ($lowStock as $item) {
  echo "{$item->name}: {$item->stock} remaining\n";
}
```

## License

MIT License
