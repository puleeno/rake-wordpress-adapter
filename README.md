# RAKE WORDPRESS ADAPTER
**Phiên bản:** 1.0
**Ngày tạo:** 2025
**Tác giả:** Development Team

---

## 📋 MỤC LỤC

1. [Tổng quan WordPress Adapter](#tổng-quan-wordpress-adapter)
2. [Mối quan hệ với Rake Core](#mối-quan-hệ-với-rake-core)
3. [Kiến trúc Adapter](#kiến-trúc-adapter)
4. [Database Integration](#database-integration)
5. [WordPress Integration](#wordpress-integration)
6. [Cách sử dụng](#cách-sử-dụng)
7. [Tài liệu kỹ thuật](#tài-liệu-kỹ-thuật)
8. [Development Guidelines](#development-guidelines)

---

## 🎯 TỔNG QUAN WORDPRESS ADAPTER

### Mục tiêu
Rake WordPress Adapter là bridge giữa Rake Core Framework và WordPress, cung cấp:

- **WordPress Database Integration**: Adapter cho WordPress database operations
- **WordPress Hooks Integration**: Tích hợp với WordPress hooks system
- **WordPress Admin Integration**: Tích hợp với WordPress admin interface
- **Security Layer**: WordPress security functions integration
- **Cache Integration**: WordPress cache system integration

### Vai trò trong hệ thống
```
┌─────────────────────────────────────────────────────────────┐
│                RAKE WORDPRESS ADAPTER                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────┐ │
│  │   DATABASE      │  │     HOOKS       │  │    ADMIN    │ │
│  │   ADAPTER       │  │   INTEGRATION   │  │ INTEGRATION │ │
│  │                 │  │                 │  │             │ │
│  │ • WP Database   │  │ • add_action    │  │ • Menu      │ │
│  │ • Query Builder │  │ • add_filter    │  │ • Pages     │ │
│  │ • Prefix Handle │  │ • do_action     │  │ • Scripts   │ │
│  │ • wpdb Wrapper  │  │ • apply_filters │  │ • Styles    │ │
│  └─────────────────┘  └─────────────────┘  └─────────────┘ │
│                                                             │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────┐ │
│  │    SECURITY     │  │      CACHE      │  │   CONFIG    │ │
│  │     LAYER       │  │   INTEGRATION   │  │ INTEGRATION │ │
│  │                 │  │                 │  │             │ │
│  │ • Nonce Check   │  │ • WP Cache      │  │ • WP Config │ │
│  │ • Capability    │  │ • Transients    │  │ • Options   │ │
│  │ • Sanitization  │  │ • Object Cache  │  │ • Settings  │ │
│  │ • Validation    │  │ • Query Cache   │  │ • Constants │ │
│  └─────────────────┘  └─────────────────┘  └─────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔗 MỐI QUAN HỆ VỚI RAKE CORE

### Dependency Chain
```
┌─────────────────┐    depends on    ┌─────────────────┐
│   CRAWFLOW      │ ────────────────▶ │ RAKE WORDPRESS  │
│   PLUGIN        │                  │    ADAPTER      │
└─────────────────┘                  └─────────────────┘
                                              │
                                              │ depends on
                                              ▼
                                    ┌─────────────────┐
                                    │   RAKE CORE     │
                                    │   FRAMEWORK     │
                                    └─────────────────┘
```

### Interface Implementation
WordPress Adapter implements các interfaces từ Rake Core:

```php
// Database Adapter Interface từ Rake Core
interface DatabaseAdapterInterface
{
    public function query(string $sql): bool;
    public function getResults(string $sql): array;
    public function getRow(string $sql): ?array;
    public function getVar(string $sql): mixed;
    public function insert(string $table, array $data): int;
    public function update(string $table, array $data, array $where): int;
    public function delete(string $table, array $where): int;
    public function getPrefix(): string;
    public function escape(string $value): string;
}

// WordPress Adapter Implementation
class WordPressDatabaseAdapter implements DatabaseAdapterInterface
{
    // Implementation cho WordPress
}
```

### Service Registration
```php
// Trong Rake Container
$container->bind(DatabaseAdapterInterface::class, WordPressDatabaseAdapter::class);
$container->bind(WordPressHooksInterface::class, WordPressHooksAdapter::class);
$container->bind(WordPressAdminInterface::class, WordPressAdminAdapter::class);
```

---

## 🏗️ KIẾN TRÚC ADAPTER

### Package Structure
```
rake-wordpress-adapter/
├── src/
│   ├── Database/              # WordPress Database Integration
│   │   ├── WordPressDatabaseAdapter.php
│   │   ├── WordPressQueryBuilder.php
│   │   └── WordPressPrefixHandler.php
│   ├── Hooks/                 # WordPress Hooks Integration
│   │   ├── WordPressHooksAdapter.php
│   │   └── WordPressHooksInterface.php
│   ├── Admin/                 # WordPress Admin Integration
│   │   ├── WordPressAdminAdapter.php
│   │   ├── WordPressMenuBuilder.php
│   │   └── WordPressScriptManager.php
│   ├── Security/              # WordPress Security Layer
│   │   ├── WordPressSecurityAdapter.php
│   │   ├── WordPressNonceHandler.php
│   │   └── WordPressCapabilityChecker.php
│   ├── Cache/                 # WordPress Cache Integration
│   │   ├── WordPressCacheAdapter.php
│   │   ├── WordPressTransientHandler.php
│   │   └── WordPressObjectCache.php
│   └── Config/                # WordPress Config Integration
│       ├── WordPressConfigAdapter.php
│       └── WordPressOptionsHandler.php
├── composer.json
└── README.md
```

### Package Dependencies
```json
{
    "name": "puleeno/rake-wordpress-adapter",
    "require": {
        "php": ">=8.1",
        "ramphor/rake": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "Rake\\WordPress\\": "src/"
        }
    }
}
```

---

## 🗄️ DATABASE INTEGRATION

### WordPress Database Adapter
```php
use Rake\WordPress\Database\WordPressDatabaseAdapter;

$adapter = new WordPressDatabaseAdapter();

// Basic operations
$adapter->insert('posts', [
    'post_title' => 'Test Post',
    'post_content' => 'Test content',
    'post_status' => 'publish'
]);

$posts = $adapter->getResults("SELECT * FROM {$adapter->getPrefix()}posts WHERE post_type = 'post'");

$adapter->update('posts',
    ['post_status' => 'draft'],
    ['ID' => 1]
);

$adapter->delete('posts', ['ID' => 1]);
```

### WordPress Query Builder
```php
use Rake\WordPress\Database\WordPressQueryBuilder;

$query = new WordPressQueryBuilder($adapter);

$posts = $query->select(['ID', 'post_title', 'post_content'])
    ->from('posts')
    ->where('post_type', '=', 'post')
    ->where('post_status', '=', 'publish')
    ->orderBy('post_date', 'DESC')
    ->limit(10)
    ->get();
```

### Prefix Handling
```php
// Tự động xử lý WordPress table prefix
$adapter = new WordPressDatabaseAdapter();
echo $adapter->getPrefix(); // wp_

// Tự động thêm prefix khi cần
$table = $adapter->addPrefix('posts'); // wp_posts
```

---

## 🔧 WORDPRESS INTEGRATION

### WordPress Hooks Integration
```php
use Rake\WordPress\Hooks\WordPressHooksAdapter;

$hooks = new WordPressHooksAdapter();

// Add actions
$hooks->addAction('init', [$this, 'initialize']);
$hooks->addAction('wp_loaded', [$this, 'onWpLoaded']);

// Add filters
$hooks->addFilter('the_content', [$this, 'modifyContent']);

// Do actions
$hooks->doAction('custom_action', $data);

// Apply filters
$modified = $hooks->applyFilters('custom_filter', $value);
```

### WordPress Admin Integration
```php
use Rake\WordPress\Admin\WordPressAdminAdapter;

$admin = new WordPressAdminAdapter();

// Add menu pages
$admin->addMenuPage(
    'My Plugin',
    'My Plugin',
    'manage_options',
    'my-plugin',
    [$this, 'renderPage']
);

// Enqueue scripts
$admin->enqueueScript('my-script', '/path/to/script.js');

// Enqueue styles
$admin->enqueueStyle('my-style', '/path/to/style.css');
```

### WordPress Security Layer
```php
use Rake\WordPress\Security\WordPressSecurityAdapter;

$security = new WordPressSecurityAdapter();

// Nonce verification
if ($security->verifyNonce($_POST['nonce'], 'my_action')) {
    // Process form
}

// Capability checking
if ($security->currentUserCan('manage_options')) {
    // Admin action
}

// Data sanitization
$clean = $security->sanitizeTextField($_POST['data']);
```

---

## 🚀 CÁCH SỬ DỤNG

### 1. Cài đặt

#### Composer Installation
```bash
composer require puleeno/rake-wordpress-adapter
```

#### Manual Installation
```bash
git clone https://github.com/puleeno/rake-wordpress-adapter.git
cd rake-wordpress-adapter
composer install
```

### 2. Khởi tạo với Rake Core

```php
use Rake\Rake;
use Rake\WordPress\Database\WordPressDatabaseAdapter;
use Rake\WordPress\Hooks\WordPressHooksAdapter;
use Rake\WordPress\Admin\WordPressAdminAdapter;

// Tạo Rake container
$app = new Rake();

// Register WordPress adapters
$app->singleton(DatabaseAdapterInterface::class, WordPressDatabaseAdapter::class);
$app->singleton(WordPressHooksInterface::class, WordPressHooksAdapter::class);
$app->singleton(WordPressAdminInterface::class, WordPressAdminAdapter::class);

// Bootstrap
$app->make(WordPressHooksInterface::class);
```

### 3. Sử dụng Database Adapter

```php
// Basic CRUD operations
$adapter = new WordPressDatabaseAdapter();

// Insert
$postId = $adapter->insert('posts', [
    'post_title' => 'New Post',
    'post_content' => 'Post content',
    'post_status' => 'publish',
    'post_type' => 'post'
]);

// Select
$posts = $adapter->getResults("
    SELECT * FROM {$adapter->getPrefix()}posts
    WHERE post_type = 'post'
    ORDER BY post_date DESC
    LIMIT 10
");

// Update
$affected = $adapter->update('posts',
    ['post_status' => 'draft'],
    ['ID' => $postId]
);

// Delete
$deleted = $adapter->delete('posts', ['ID' => $postId]);
```

### 4. Sử dụng Hooks Adapter

```php
$hooks = new WordPressHooksAdapter();

// Register plugin hooks
$hooks->addAction('plugins_loaded', function() {
    // Plugin initialization
});

$hooks->addAction('admin_menu', function() {
    // Add admin menu
});

$hooks->addFilter('the_title', function($title) {
    return 'Modified: ' . $title;
});
```

### 5. Sử dụng Admin Adapter

```php
$admin = new WordPressAdminAdapter();

// Add admin menu
$admin->addMenuPage(
    'My Plugin',
    'My Plugin',
    'manage_options',
    'my-plugin',
    function() {
        echo '<div class="wrap"><h1>My Plugin</h1></div>';
    }
);

// Enqueue admin assets
$admin->enqueueScript('my-admin-script', '/js/admin.js');
$admin->enqueueStyle('my-admin-style', '/css/admin.css');
```

### 6. Sử dụng Security Adapter

```php
$security = new WordPressSecurityAdapter();

// Form processing
if ($_POST && $security->verifyNonce($_POST['nonce'], 'save_data')) {
    if ($security->currentUserCan('manage_options')) {
        $cleanData = $security->sanitizeTextField($_POST['data']);
        // Process data
    }
}
```

### 7. Sử dụng Cache Adapter

```php
$cache = new WordPressCacheAdapter();

// Set cache
$cache->set('my_key', $data, 3600); // 1 hour

// Get cache
$data = $cache->get('my_key');

// Delete cache
$cache->delete('my_key');
```

---

## 📚 TÀI LIỆU KỸ THUẬT

### Tài liệu chi tiết
📖 [`docs/technical-documentation.md`](docs/technical-documentation.md)

**Nội dung:**
- WordPress Database Integration
- WordPress Hooks Integration
- WordPress Admin Integration
- Security Layer
- Cache Integration
- Development Guidelines

### Code Examples

#### Database Operations
```php
// Transaction handling
$adapter->beginTransaction();
try {
    $adapter->insert('posts', $postData);
    $adapter->insert('postmeta', $metaData);
    $adapter->commit();
} catch (Exception $e) {
    $adapter->rollback();
    throw $e;
}
```

#### Hook Integration
```php
// Custom hooks
$hooks->addAction('my_custom_hook', function($data) {
    // Process data
});

$hooks->doAction('my_custom_hook', $data);
```

#### Admin Integration
```php
// Submenu pages
$admin->addSubmenuPage(
    'my-plugin',
    'Settings',
    'Settings',
    'manage_options',
    'my-plugin-settings',
    [$this, 'renderSettings']
);
```

---

## 🛠️ DEVELOPMENT GUIDELINES

### Coding Standards

#### WordPress Integration Best Practices
```php
// Always use WordPress functions with backslash prefix
$result = \wp_verify_nonce($nonce, $action);

// Use WordPress security functions
$sanitized = \sanitize_text_field($input);

// Check capabilities before actions
if (\current_user_can('manage_options')) {
    // Perform admin action
}

// Use WordPress hooks properly
\add_action('init', [$this, 'initialize']);
```

#### PSR-12 Compliance
```php
<?php

declare(strict_types=1);

namespace Rake\WordPress\Database;

use Rake\Database\DatabaseAdapterInterface;

class WordPressDatabaseAdapter implements DatabaseAdapterInterface
{
    private \wpdb $wpdb;
    private string $prefix;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix;
    }

    public function query(string $sql): bool
    {
        return $this->wpdb->query($sql) !== false;
    }
}
```

### Testing Guidelines

#### Unit Testing
```php
class WordPressDatabaseAdapterTest extends TestCase
{
    private WordPressDatabaseAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new WordPressDatabaseAdapter();
    }

    public function testInsert(): void
    {
        // Arrange
        $data = ['post_title' => 'Test Post'];

        // Act
        $id = $this->adapter->insert('posts', $data);

        // Assert
        $this->assertGreaterThan(0, $id);
    }
}
```

#### Integration Testing
```php
class WordPressIntegrationTest extends TestCase
{
    public function testDatabaseAdapter(): void
    {
        // Arrange
        $adapter = new WordPressDatabaseAdapter();

        // Act
        $result = $adapter->query('SELECT 1');

        // Assert
        $this->assertTrue($result);
    }
}
```

### Error Handling
```php
class WordPressException extends Exception
{
    public function __construct(string $message, array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("WordPress error: {$message}", $code, $previous);
    }
}

// Usage
try {
    $adapter = new WordPressDatabaseAdapter();
    $result = $adapter->insert('table', $data);
} catch (WordPressException $e) {
    Logger::error('WordPress operation failed: ' . $e->getMessage());
}
```

---

## 🔧 CONFIGURATION

### WordPress Settings
Adapter tự động sử dụng WordPress database settings:

```php
// Tự động detect từ WordPress
$adapter = new WordPressDatabaseAdapter();
echo $adapter->getPrefix();        // wp_
echo $adapter->getCharset();       // utf8mb4
echo $adapter->getCollation();     // utf8mb4_unicode_ci
```

### Custom Configuration
```php
// Nếu cần custom settings
$adapter = new WordPressDatabaseAdapter();

// Custom database operations
$adapter->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
```

---

## 🚨 TROUBLESHOOTING

### Common Issues

#### Error: `Class 'Rake\WordPress\Database\WordPressDatabaseAdapter' not found`
**Solution:**
```bash
composer dump-autoload
```

#### Error: `WordPress not loaded`
**Solution:**
```php
// Ensure WordPress is loaded
require_once 'wp-load.php';
```

#### Error: `Database connection failed`
**Solution:**
- Check WordPress database configuration
- Verify database credentials
- Check database server status

### Debug Mode
```php
// Enable debug mode
$adapter = new WordPressDatabaseAdapter();

// Check last error
echo $adapter->getLastError();

// Check affected rows
echo $adapter->affectedRows();
```

---

## 📊 PERFORMANCE

### Optimizations
- **Prepared statements**: Sử dụng WordPress prepared statements
- **Connection reuse**: Tái sử dụng WordPress database connection
- **Query optimization**: Tối ưu queries cho WordPress
- **Memory management**: Efficient memory usage

### Best Practices
```php
// Use transactions for multiple operations
$adapter->beginTransaction();
try {
    foreach ($posts as $post) {
        $adapter->insert('posts', $post);
    }
    $adapter->commit();
} catch (Exception $e) {
    $adapter->rollback();
    throw $e;
}

// Use batch operations
$adapter->getResults("SELECT * FROM posts LIMIT 1000");

// Use specific columns
$adapter->getResults("SELECT ID, post_title FROM posts WHERE post_type = 'post'");
```

---

## 🎯 KẾT LUẬN

Rake WordPress Adapter cung cấp bridge hoàn chỉnh giữa Rake Core Framework và WordPress với:

### Điểm nổi bật:
1. **Database Integration**: WordPress database adapter với prefix handling
2. **Hooks Integration**: WordPress hooks system integration
3. **Admin Integration**: WordPress admin interface integration
4. **Security Layer**: WordPress security functions integration
5. **Cache Integration**: WordPress cache system integration

### Sử dụng:
```php
// Initialize adapter
$adapter = new WordPressDatabaseAdapter();
$hooks = new WordPressHooksAdapter();
$admin = new WordPressAdminAdapter();

// Use database
$results = $adapter->getResults('SELECT * FROM wp_posts');

// Use hooks
$hooks->addAction('init', [$this, 'initialize']);

// Use admin
$admin->addMenuPage('My Plugin', 'My Plugin', 'manage_options', 'my-plugin', [$this, 'renderPage']);
```

---

**Tài liệu này sẽ được cập nhật thường xuyên khi có thay đổi trong adapter.**
