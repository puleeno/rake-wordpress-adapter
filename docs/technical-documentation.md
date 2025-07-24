# TÀI LIỆU THIẾT KẾ KỸ THUẬT RAKE WORDPRESS ADAPTER
**Phiên bản:** 1.0
**Ngày tạo:** 2025
**Tác giả:** Development Team

---

## MỤC LỤC

1. [Tổng quan WordPress Adapter](#1-tổng-quan-wordpress-adapter)
2. [Kiến trúc Adapter](#2-kiến-trúc-adapter)
3. [Database Integration](#3-database-integration)
4. [WordPress Hooks Integration](#4-wordpress-hooks-integration)
5. [WordPress Admin Integration](#5-wordpress-admin-integration)
6. [Security Layer](#6-security-layer)
7. [Cache Integration](#7-cache-integration)
8. [Development Guidelines](#8-development-guidelines)

---

## 1. TỔNG QUAN WORDPRESS ADAPTER

### 1.1 Mục tiêu
Rake WordPress Adapter là bridge giữa Rake Core Framework và WordPress, cung cấp:
- WordPress Database Integration
- WordPress Hooks Integration
- WordPress Admin Integration
- WordPress Security Layer
- WordPress Cache Integration

### 1.2 Kiến trúc tổng thể
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

## 2. KIẾN TRÚC ADAPTER

### 2.1 Package Structure
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

### 2.2 Package Dependencies
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

## 3. DATABASE INTEGRATION

### 3.1 WordPress Database Adapter
```php
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

    public function getResults(string $sql): array
    {
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    public function getRow(string $sql): ?array
    {
        $result = $this->wpdb->get_row($sql, ARRAY_A);
        return $result ?: null;
    }

    public function getVar(string $sql): mixed
    {
        return $this->wpdb->get_var($sql);
    }

    public function insert(string $table, array $data): int
    {
        $table = $this->addPrefix($table);
        $result = $this->wpdb->insert($table, $data);

        if ($result === false) {
            throw new DatabaseException('Insert failed: ' . $this->wpdb->last_error);
        }

        return $this->wpdb->insert_id;
    }

    public function update(string $table, array $data, array $where): int
    {
        $table = $this->addPrefix($table);
        $result = $this->wpdb->update($table, $data, $where);

        if ($result === false) {
            throw new DatabaseException('Update failed: ' . $this->wpdb->last_error);
        }

        return $result;
    }

    public function delete(string $table, array $where): int
    {
        $table = $this->addPrefix($table);
        $result = $this->wpdb->delete($table, $where);

        if ($result === false) {
            throw new DatabaseException('Delete failed: ' . $this->wpdb->last_error);
        }

        return $result;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function escape(string $value): string
    {
        return $this->wpdb->_real_escape($value);
    }

    private function addPrefix(string $table): string
    {
        if (strpos($table, $this->prefix) === 0) {
            return $table;
        }
        return $this->prefix . $table;
    }

    public function tableExists(string $table): bool
    {
        $sql = "SHOW TABLES LIKE '{$this->addPrefix($table)}'";
        $result = $this->getRow($sql);
        return $result !== null;
    }

    public function getTables(): array
    {
        $sql = "SHOW TABLES LIKE '{$this->prefix}%'";
        $results = $this->getResults($sql);

        $tables = [];
        foreach ($results as $result) {
            $tables[] = array_values($result)[0];
        }

        return $tables;
    }
}
```

### 3.2 WordPress Query Builder
```php
class WordPressQueryBuilder
{
    private WordPressDatabaseAdapter $adapter;
    private array $select = [];
    private array $from = [];
    private array $where = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(WordPressDatabaseAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function from(string $table): self
    {
        $this->from[] = $table;
        return $this;
    }

    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [
            'column' => $column,
            'direction' => strtoupper($direction),
        ];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        $sql = $this->buildSelectQuery();
        return $this->adapter->getResults($sql);
    }

    public function first(): ?array
    {
        $this->limit(1);
        $sql = $this->buildSelectQuery();
        return $this->adapter->getRow($sql);
    }

    private function buildSelectQuery(): string
    {
        $sql = "SELECT " . implode(', ', $this->select ?: ['*']);
        $sql .= " FROM " . implode(', ', $this->from);

        if (!empty($this->where)) {
            $sql .= " WHERE " . $this->buildWhereClause();
        }

        if (!empty($this->groupBy)) {
            $sql .= " GROUP BY " . implode(', ', $this->groupBy);
        }

        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . $this->buildOrderByClause();
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    private function buildWhereClause(): string
    {
        $conditions = [];
        foreach ($this->where as $condition) {
            $value = $this->adapter->escape($condition['value']);
            $conditions[] = "{$condition['column']} {$condition['operator']} '{$value}'";
        }
        return implode(' AND ', $conditions);
    }

    private function buildOrderByClause(): string
    {
        $orders = [];
        foreach ($this->orderBy as $order) {
            $orders[] = "{$order['column']} {$order['direction']}";
        }
        return implode(', ', $orders);
    }
}
```

---

## 4. WORDPRESS HOOKS INTEGRATION

### 4.1 WordPress Hooks Adapter
```php
class WordPressHooksAdapter implements WordPressHooksInterface
{
    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        \add_action($hook, $callback, $priority, $acceptedArgs);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        \add_filter($hook, $callback, $priority, $acceptedArgs);
    }

    public function doAction(string $hook, ...$args): void
    {
        \do_action($hook, ...$args);
    }

    public function applyFilters(string $hook, $value, ...$args)
    {
        return \apply_filters($hook, $value, ...$args);
    }

    public function removeAction(string $hook, callable $callback, int $priority = 10): void
    {
        \remove_action($hook, $callback, $priority);
    }

    public function removeFilter(string $hook, callable $callback, int $priority = 10): void
    {
        \remove_filter($hook, $callback, $priority);
    }

    public function hasAction(string $hook): bool
    {
        return \has_action($hook);
    }

    public function hasFilter(string $hook): bool
    {
        return \has_filter($hook);
    }

    public function currentFilter(): string
    {
        return \current_filter();
    }

    public function doingAction(string $hook): bool
    {
        return \doing_action($hook);
    }

    public function doingFilter(string $hook): bool
    {
        return \doing_filter($hook);
    }
}
```

### 4.2 WordPress Hooks Interface
```php
interface WordPressHooksInterface
{
    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;
    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;
    public function doAction(string $hook, ...$args): void;
    public function applyFilters(string $hook, $value, ...$args);
    public function removeAction(string $hook, callable $callback, int $priority = 10): void;
    public function removeFilter(string $hook, callable $callback, int $priority = 10): void;
    public function hasAction(string $hook): bool;
    public function hasFilter(string $hook): bool;
    public function currentFilter(): string;
    public function doingAction(string $hook): bool;
    public function doingFilter(string $hook): bool;
}
```

---

## 5. WORDPRESS ADMIN INTEGRATION

### 5.1 WordPress Admin Adapter
```php
class WordPressAdminAdapter
{
    public function addMenuPage(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback): void
    {
        \add_menu_page($pageTitle, $menuTitle, $capability, $menuSlug, $callback);
    }

    public function addSubmenuPage(string $parentSlug, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback): void
    {
        \add_submenu_page($parentSlug, $pageTitle, $menuTitle, $capability, $menuSlug, $callback);
    }

    public function enqueueScript(string $handle, string $src, array $deps = [], $version = false, bool $inFooter = false): void
    {
        \wp_enqueue_script($handle, $src, $deps, $version, $inFooter);
    }

    public function enqueueStyle(string $handle, string $src, array $deps = [], $version = false): void
    {
        \wp_enqueue_style($handle, $src, $deps, $version);
    }

    public function localizeScript(string $handle, string $objectName, array $data): void
    {
        \wp_localize_script($handle, $objectName, $data);
    }

    public function addAdminNotice(string $message, string $type = 'info'): void
    {
        \add_action('admin_notices', function() use ($message, $type) {
            $class = 'notice notice-' . $type;
            echo "<div class='{$class}'><p>{$message}</p></div>";
        });
    }

    public function getCurrentScreen(): ?object
    {
        return \get_current_screen();
    }

    public function getAdminUrl(string $path = '', array $args = []): string
    {
        return \admin_url($path, $args);
    }

    public function getPluginUrl(string $path = ''): string
    {
        return \plugin_dir_url(__FILE__) . $path;
    }

    public function getPluginPath(string $path = ''): string
    {
        return \plugin_dir_path(__FILE__) . $path;
    }
}
```

### 5.2 WordPress Menu Builder
```php
class WordPressMenuBuilder
{
    private WordPressAdminAdapter $adminAdapter;

    public function __construct(WordPressAdminAdapter $adminAdapter)
    {
        $this->adminAdapter = $adminAdapter;
    }

    public function addMainMenu(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback, string $icon = '', int $position = null): void
    {
        \add_menu_page($pageTitle, $menuTitle, $capability, $menuSlug, $callback, $icon, $position);
    }

    public function addSubMenu(string $parentSlug, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback): void
    {
        \add_submenu_page($parentSlug, $pageTitle, $menuTitle, $capability, $menuSlug, $callback);
    }

    public function removeSubMenu(string $parentSlug, string $menuSlug): void
    {
        \remove_submenu_page($parentSlug, $menuSlug);
    }

    public function addAdminBarNode(string $id, string $title, string $href = '', string $parent = '', array $meta = []): void
    {
        \add_action('admin_bar_menu', function($wp_admin_bar) use ($id, $title, $href, $parent, $meta) {
            $wp_admin_bar->add_node([
                'id' => $id,
                'title' => $title,
                'href' => $href,
                'parent' => $parent,
                'meta' => $meta,
            ]);
        }, 999);
    }
}
```

### 5.3 WordPress Script Manager
```php
class WordPressScriptManager
{
    private WordPressAdminAdapter $adminAdapter;

    public function __construct(WordPressAdminAdapter $adminAdapter)
    {
        $this->adminAdapter = $adminAdapter;
    }

    public function enqueueAdminScript(string $handle, string $src, array $deps = [], $version = false): void
    {
        \add_action('admin_enqueue_scripts', function() use ($handle, $src, $deps, $version) {
            $this->adminAdapter->enqueueScript($handle, $src, $deps, $version);
        });
    }

    public function enqueueAdminStyle(string $handle, string $src, array $deps = [], $version = false): void
    {
        \add_action('admin_enqueue_scripts', function() use ($handle, $src, $deps, $version) {
            $this->adminAdapter->enqueueStyle($handle, $src, $deps, $version);
        });
    }

    public function enqueueFrontendScript(string $handle, string $src, array $deps = [], $version = false): void
    {
        \add_action('wp_enqueue_scripts', function() use ($handle, $src, $deps, $version) {
            $this->adminAdapter->enqueueScript($handle, $src, $deps, $version);
        });
    }

    public function enqueueFrontendStyle(string $handle, string $src, array $deps = [], $version = false): void
    {
        \add_action('wp_enqueue_scripts', function() use ($handle, $src, $deps, $version) {
            $this->adminAdapter->enqueueStyle($handle, $src, $deps, $version);
        });
    }

    public function localizeScript(string $handle, string $objectName, array $data): void
    {
        \add_action('admin_enqueue_scripts', function() use ($handle, $objectName, $data) {
            $this->adminAdapter->localizeScript($handle, $objectName, $data);
        });
    }
}
```

---

## 6. SECURITY LAYER

### 6.1 WordPress Security Adapter
```php
class WordPressSecurityAdapter
{
    public function verifyNonce(string $nonce, string $action): bool
    {
        return \wp_verify_nonce($nonce, $action);
    }

    public function createNonce(string $action): string
    {
        return \wp_create_nonce($action);
    }

    public function checkAjaxReferer(string $action, string $queryArg = '_wpnonce', bool $die = true): bool
    {
        return \check_ajax_referer($action, $queryArg, $die);
    }

    public function currentUserCan(string $capability): bool
    {
        return \current_user_can($capability);
    }

    public function sanitizeTextField(string $text): string
    {
        return \sanitize_text_field($text);
    }

    public function sanitizeTextareaField(string $text): string
    {
        return \sanitize_textarea_field($text);
    }

    public function sanitizeEmail(string $email): string
    {
        return \sanitize_email($email);
    }

    public function sanitizeUrl(string $url): string
    {
        return \esc_url_raw($url);
    }

    public function wpDie(string $message = '', string $title = '', array $args = []): void
    {
        \wp_die($message, $title, $args);
    }

    public function isAjax(): bool
    {
        return \wp_doing_ajax();
    }

    public function isAdmin(): bool
    {
        return \is_admin();
    }
}
```

### 6.2 WordPress Nonce Handler
```php
class WordPressNonceHandler
{
    private WordPressSecurityAdapter $securityAdapter;

    public function __construct(WordPressSecurityAdapter $securityAdapter)
    {
        $this->securityAdapter = $securityAdapter;
    }

    public function createNonce(string $action): string
    {
        return $this->securityAdapter->createNonce($action);
    }

    public function verifyNonce(string $nonce, string $action): bool
    {
        return $this->securityAdapter->verifyNonce($nonce, $action);
    }

    public function createNonceField(string $action, string $name = '_wpnonce', bool $referer = true): string
    {
        return \wp_nonce_field($action, $name, $referer, false);
    }

    public function createNonceUrl(string $actionUrl, string $action, string $name = '_wpnonce'): string
    {
        return \wp_nonce_url($actionUrl, $action, $name);
    }

    public function checkAjaxReferer(string $action, string $queryArg = '_wpnonce', bool $die = true): bool
    {
        return $this->securityAdapter->checkAjaxReferer($action, $queryArg, $die);
    }
}
```

### 6.3 WordPress Capability Checker
```php
class WordPressCapabilityChecker
{
    private WordPressSecurityAdapter $securityAdapter;

    public function __construct(WordPressSecurityAdapter $securityAdapter)
    {
        $this->securityAdapter = $securityAdapter;
    }

    public function canManageOptions(): bool
    {
        return $this->securityAdapter->currentUserCan('manage_options');
    }

    public function canEditPosts(): bool
    {
        return $this->securityAdapter->currentUserCan('edit_posts');
    }

    public function canEditPages(): bool
    {
        return $this->securityAdapter->currentUserCan('edit_pages');
    }

    public function canPublishPosts(): bool
    {
        return $this->securityAdapter->currentUserCan('publish_posts');
    }

    public function canUploadFiles(): bool
    {
        return $this->securityAdapter->currentUserCan('upload_files');
    }

    public function canActivatePlugins(): bool
    {
        return $this->securityAdapter->currentUserCan('activate_plugins');
    }

    public function canInstallPlugins(): bool
    {
        return $this->securityAdapter->currentUserCan('install_plugins');
    }

    public function canUpdateCore(): bool
    {
        return $this->securityAdapter->currentUserCan('update_core');
    }

    public function requireCapability(string $capability): void
    {
        if (!$this->securityAdapter->currentUserCan($capability)) {
            $this->securityAdapter->wpDie('Insufficient permissions');
        }
    }
}
```

---

## 7. CACHE INTEGRATION

### 7.1 WordPress Cache Adapter
```php
class WordPressCacheAdapter
{
    public function get(string $key, $default = null)
    {
        $value = \wp_cache_get($key);
        return $value !== false ? $value : $default;
    }

    public function set(string $key, $value, int $expiration = 0): bool
    {
        return \wp_cache_set($key, $value, '', $expiration);
    }

    public function delete(string $key): bool
    {
        return \wp_cache_delete($key);
    }

    public function flush(): bool
    {
        return \wp_cache_flush();
    }

    public function add(string $key, $value, int $expiration = 0): bool
    {
        return \wp_cache_add($key, $value, '', $expiration);
    }

    public function replace(string $key, $value, int $expiration = 0): bool
    {
        return \wp_cache_replace($key, $value, '', $expiration);
    }

    public function increment(string $key, int $offset = 1): int
    {
        return \wp_cache_incr($key, $offset);
    }

    public function decrement(string $key, int $offset = 1): int
    {
        return \wp_cache_decr($key, $offset);
    }
}
```

### 7.2 WordPress Transient Handler
```php
class WordPressTransientHandler
{
    public function get(string $key, $default = null)
    {
        $value = \get_transient($key);
        return $value !== false ? $value : $default;
    }

    public function set(string $key, $value, int $expiration = 0): bool
    {
        return \set_transient($key, $value, $expiration);
    }

    public function delete(string $key): bool
    {
        return \delete_transient($key);
    }

    public function getSiteTransient(string $key, $default = null)
    {
        $value = \get_site_transient($key);
        return $value !== false ? $value : $default;
    }

    public function setSiteTransient(string $key, $value, int $expiration = 0): bool
    {
        return \set_site_transient($key, $value, $expiration);
    }

    public function deleteSiteTransient(string $key): bool
    {
        return \delete_site_transient($key);
    }
}
```

### 7.3 WordPress Object Cache
```php
class WordPressObjectCache
{
    private WordPressCacheAdapter $cacheAdapter;

    public function __construct(WordPressCacheAdapter $cacheAdapter)
    {
        $this->cacheAdapter = $cacheAdapter;
    }

    public function getObject(string $key, $default = null)
    {
        $value = $this->cacheAdapter->get($key, $default);
        return is_string($value) ? unserialize($value) : $value;
    }

    public function setObject(string $key, $object, int $expiration = 0): bool
    {
        $serialized = serialize($object);
        return $this->cacheAdapter->set($key, $serialized, $expiration);
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->cacheAdapter->get($key, $default);
        return is_array($value) ? $value : $default;
    }

    public function setArray(string $key, array $array, int $expiration = 0): bool
    {
        return $this->cacheAdapter->set($key, $array, $expiration);
    }

    public function getJson(string $key, $default = null)
    {
        $value = $this->cacheAdapter->get($key, $default);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
        }
        return $value;
    }

    public function setJson(string $key, $data, int $expiration = 0): bool
    {
        $json = json_encode($data);
        return $this->cacheAdapter->set($key, $json, $expiration);
    }
}
```

---

## 8. DEVELOPMENT GUIDELINES

### 8.1 Coding Standards
- **WordPress Coding Standards**: Follow WordPress coding standards
- **PSR-12 Compliance**: Adhere to PSR-12 for non-WordPress specific code
- **Type Declarations**: Use strict types and type hints
- **Documentation**: PHPDoc required for all public methods

### 8.2 WordPress Integration Best Practices
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

### 8.3 Testing Guidelines
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

    public function testSecurityAdapter(): void
    {
        // Arrange
        $adapter = new WordPressSecurityAdapter();
        $nonce = \wp_create_nonce('test_action');

        // Act
        $result = $adapter->verifyNonce($nonce, 'test_action');

        // Assert
        $this->assertTrue($result);
    }
}
```

### 8.4 Error Handling
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

## KẾT LUẬN

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