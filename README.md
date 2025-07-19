# WordPress Adapter for Rake 2.0

WordPress adapter cho Rake 2.0 framework, cung cấp integration với WordPress database và các tính năng WordPress.

## Cài đặt

```bash
composer require puleeno/rake-wordpress-adapter
```

## Cấu trúc

```
src/
├── Adapter/
│   └── WordPressDatabaseAdapter.php    # High-level database operations
├── Driver/
│   └── WordPressDatabaseDriver.php     # Low-level database operations
├── Processor/
│   └── WordPressProcessor.php          # WordPress-specific processing
└── WordPressResourceManager.php        # WordPress resource management
```

## Sử dụng

### 1. WordPress Database Driver

Driver xử lý low-level database operations sử dụng WordPress `$wpdb`:

```php
use Puleeno\Rake\WordPress\Driver\WordPressDatabaseDriver;

$driver = new WordPressDatabaseDriver();

// Execute query
$result = $driver->query("SELECT * FROM wp_posts WHERE post_type = 'post'");

// Execute without results
$success = $driver->execute("UPDATE wp_posts SET post_status = 'publish'");

// Transactions
$driver->beginTransaction();
$driver->execute("INSERT INTO wp_posts (post_title) VALUES ('Test')");
$driver->commit();
```

### 2. WordPress Database Adapter

Adapter cung cấp high-level database operations:

```php
use Puleeno\Rake\WordPress\Adapter\WordPressDatabaseAdapter;

$adapter = new WordPressDatabaseAdapter();

// Insert data
$adapter->insert('wp_posts', [
    'post_title' => 'Test Post',
    'post_content' => 'Test content',
    'post_status' => 'publish',
    'post_type' => 'post'
]);

// Select data
$posts = $adapter->select('wp_posts', ['*'], [
    'post_type' => 'post',
    'post_status' => 'publish'
], 10, ['post_date' => 'DESC']);

// Update data
$adapter->update('wp_posts',
    ['post_status' => 'draft'],
    ['ID' => 1]
);

// Delete data
$adapter->delete('wp_posts', ['ID' => 1]);

// Count rows
$count = $adapter->count('wp_posts', ['post_type' => 'post']);

// Get single row
$post = $adapter->get('wp_posts', ['*'], ['ID' => 1]);
```

### 3. Integration với Rake

```php
use Puleeno\Rake\WordPress\Adapter\WordPressDatabaseAdapter;
use Rake\Manager\Database\MigrationManager;
use Rake\Database\SchemaGenerator;

// Create adapter
$adapter = new WordPressDatabaseAdapter();

// Use with MigrationManager
$migrationManager = new MigrationManager($adapter);

// Use with SchemaGenerator
$schemaGenerator = new SchemaGenerator($adapter);
```

## Tính năng

### 1. WordPress Integration

- **Automatic prefix detection**: Tự động sử dụng WordPress table prefix
- **WordPress constants**: Sử dụng `DB_NAME`, `DB_HOST`, etc.
- **WordPress charset/collation**: Tự động sử dụng WordPress database settings
- **WordPress security**: Sử dụng WordPress prepared statements

### 2. Database Operations

- **CRUD operations**: Insert, select, update, delete
- **Transactions**: Begin, commit, rollback
- **Table management**: Create, drop, structure inspection
- **Index management**: Create, drop, inspect indexes
- **Schema inspection**: Get table structure and indexes

### 3. WordPress Features

- **Post management**: Create, update, delete WordPress posts
- **User management**: Handle WordPress users
- **Meta management**: Handle post/user meta
- **Taxonomy management**: Handle categories, tags
- **Media management**: Handle attachments

## Testing

### 1. Run Tests

```bash
cd rake-wordpress-adapter
php test-adapter.php
```

### 2. Expected Output

```
=== WordPress Adapter Test ===

1. Testing WordPress Database Driver...
   ✓ Driver created successfully
   - Database name: wordpress_db
   - Charset: utf8mb4
   - Collation: utf8mb4_unicode_ci

2. Testing WordPress Database Adapter...
   ✓ Adapter created successfully

3. Testing database operations...
   - Create table: ✓
   - Insert data: ✓
   - Select data: ✓
   - Update data: ✓
   - Count rows: 1
   - Get single row: ✓

4. Testing transactions...
   - Begin transaction: ✓
   - Insert in transaction: ✓
   - Commit transaction: ✓
   - Rollback transaction: ✓

5. Cleaning up...
   - Drop test table: ✓

=== Test completed successfully ===
```

## Configuration

### 1. WordPress Settings

Adapter tự động sử dụng WordPress database settings:

```php
// Tự động detect từ WordPress
$driver = new WordPressDatabaseDriver();
echo $driver->getDatabaseName(); // DB_NAME
echo $driver->getCharset();      // $wpdb->charset
echo $driver->getCollation();    // $wpdb->collate
```

### 2. Custom Configuration

```php
// Nếu cần custom settings
$adapter = new WordPressDatabaseAdapter();
$driver = $adapter->getDriver();

// Custom database operations
$driver->execute("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
```

## Error Handling

### 1. Database Errors

```php
try {
    $adapter->insert('wp_posts', [
        'post_title' => 'Test',
        'post_content' => 'Content'
    ]);
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage();
    echo "Last SQL error: " . $adapter->getLastError();
}
```

### 2. Transaction Errors

```php
try {
    $adapter->beginTransaction();

    $adapter->insert('wp_posts', ['post_title' => 'Post 1']);
    $adapter->insert('wp_posts', ['post_title' => 'Post 2']);

    $adapter->commit();
} catch (Exception $e) {
    $adapter->rollback();
    echo "Transaction failed: " . $e->getMessage();
}
```

## Performance

### 1. Optimizations

- **Prepared statements**: Sử dụng WordPress prepared statements
- **Connection reuse**: Tái sử dụng WordPress database connection
- **Query optimization**: Tối ưu queries cho WordPress
- **Memory management**: Efficient memory usage

### 2. Best Practices

```php
// Use transactions for multiple operations
$adapter->beginTransaction();
try {
    foreach ($posts as $post) {
        $adapter->insert('wp_posts', $post);
    }
    $adapter->commit();
} catch (Exception $e) {
    $adapter->rollback();
    throw $e;
}

// Use batch operations
$adapter->select('wp_posts', ['*'], [], 1000); // Limit results

// Use specific columns
$adapter->select('wp_posts', ['ID', 'post_title'], ['post_type' => 'post']);
```

## Troubleshooting

### 1. Common Issues

**Error**: `Class 'Puleeno\Rake\WordPress\Adapter\WordPressDatabaseAdapter' not found`

**Solution**:
```bash
composer dump-autoload
```

**Error**: `WordPress not loaded`

**Solution**:
```php
// Ensure WordPress is loaded
require_once 'wp-load.php';
```

**Error**: `Database connection failed`

**Solution**:
- Check WordPress database configuration
- Verify database credentials
- Check database server status

### 2. Debug Mode

```php
// Enable debug mode
$adapter = new WordPressDatabaseAdapter();
$driver = $adapter->getDriver();

// Check last error
echo $driver->getLastError();

// Check affected rows
echo $driver->affectedRows();
```

## License

MIT License - see LICENSE file for details.
