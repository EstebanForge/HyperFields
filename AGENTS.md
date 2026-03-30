# Project Overview

HyperFields is a decoupled WordPress library that provides a comprehensive custom field system. It is used as a Composer dependency for other plugins (like HyperPress).

**Package**: `estebanforge/hyperfields`
**Repository**: https://github.com/EstebanForge/HyperFields

## Installation

### As Composer Dependency
```bash
composer require estebanforge/hyperfields
```

## Development Commands

### Composer Commands
```bash
composer dump-autoload --optimize  # Regenerate optimized autoloader
composer install --no-dev --optimize-autoloader  # Production install
composer update --no-dev --optimize-autoloader   # Production update
```

### Version Management
- Update version in `composer.json`
- Update version in `bootstrap.php` fallback values (3 locations)
- Update `CHANGELOG.md` with changes

## Architecture & Key Components

### Core Systems

**Field System**: Comprehensive field type library with sanitization, validation, and conditional logic
**Container System**: Support for post meta, term meta, user meta, and options pages
**Template Loader**: Renders field UI templates with automatic asset enqueuing
**Block Field Adapter**: Integration layer for Gutenberg blocks

### Directory Structure
```
src/                    # PSR-4 autoloaded as HyperFields\
  Admin/               # Activation, options pages, migrations, export/import UI
    ExportImportUI.php # Admin submenu page for Export / Import
  Container/           # Field containers (PostMeta, TermMeta, UserMeta, Options)
  Templates/           # Field UI templates
  Assets.php          # Asset management
  BlockFieldAdapter.php  # Gutenberg integration
  ConditionalLogic.php   # Field visibility logic
  ExportImport.php    # Export / Import core logic
  Field.php           # Base field class
  HyperFields.php     # Main API class
  Registry.php        # Field registration
  TemplateLoader.php  # Template rendering system
includes/
  helpers.php         # Helper functions (hf_* prefix)
  backward-compatibility.php  # Legacy class aliases
bootstrap.php         # Bootstrap logic (version resolution, initialization)
```

### Key Classes & Their Purpose

**HyperFields\HyperFields**: Main API class for registering options pages and retrieving field values
```php
HyperFields::registerOptionsPage([...]);
HyperFields::getOptions('option_name', []);
```

**HyperFields\Registry**: Manages field registration and initialization

**HyperFields\TemplateLoader**: Renders field UI templates (text, textarea, select, etc.)
- **Note**: This is INTERNAL to HyperFields for rendering field UI
- Other plugins (like HyperPress) do NOT need to initialize this
- Automatically initialized by HyperFields bootstrap

**HyperFields\BlockFieldAdapter**: Adapter for Gutenberg block integration
- Used by HyperPress for block field definitions

**HyperFields\Field**: Base field class extended by specific field types

**HyperFields\Container\***: Container classes for different storage types
- `PostMetaContainer` - Post meta fields
- `TermMetaContainer` - Term meta fields
- `UserMetaContainer` - User meta fields
- Options stored via `HyperFields::registerOptionsPage()`

**HyperFields\ExportImport**: Core export / import logic
- `exportOptions(array $optionNames, string $prefix = ''): string` — JSON export
- `importOptions(string $json, array $allowedOptionNames = [], string $prefix = ''): array` — JSON import with backup
- `restoreBackup(string $backupKey, string $optionName): bool` — restore from transient backup
- `snapshotOptions(array $optionNames, string $prefix = ''): string` — snapshot current data (used by import preview)

**HyperFields\Admin\ExportImportUI**: Admin page for visual Export / Import
- `registerPage(...)` — registers the submenu page and hooks assets to `admin_enqueue_scripts`; recommended entry point for third-party plugins
- `enqueuePageAssets(string $hook, string $expectedHook)` — public asset enqueue method hooked to `admin_enqueue_scripts`
- `render(array $config)` — renders the full page (called by WordPress menu callback)

### Field Types

Available field types:
- text, textarea, email, url, number
- select, checkbox, radio, toggle
- date, time, datetime, color
- image, file, gallery
- wysiwyg, code_editor
- repeater, tabs, separator, heading

Each field type has its own template in `src/templates/fields/`

## Development Patterns

### Creating a New Field Type

1. Create field class extending `HyperFields\Field`
2. Create template in `src/templates/fields/`
3. Register field type in Registry
4. Add sanitization/validation logic

### Adding Options Pages

```php
use HyperFields\HyperFields;

HyperFields::registerOptionsPage([
    'page_title' => 'My Settings',
    'menu_title' => 'Settings',
    'capability' => 'manage_options',
    'menu_slug'  => 'my-settings',
    'sections'   => [...],
]);
```

### Working with Post Meta

```php
use HyperFields\Container\PostMetaContainer;

$container = new PostMetaContainer('my_meta_box', [
    'title' => 'Custom Fields',
    'post_types' => ['post', 'page'],
]);
$container->addField([...]);
```

## Bootstrap System

HyperFields uses a version resolution system that allows multiple instances to coexist:

1. Each instance registers itself as a candidate
2. The latest version wins and loads first
3. Backward-compatibility layer ensures old class names still work

**Constants defined:**
- `HYPERFIELDS_VERSION` - Current version
- `HYPERFIELDS_ABSPATH` - Plugin absolute path
- `HYPERFIELDS_PLUGIN_URL` - Plugin/library base URL
- `HYPERFIELDS_BOOTSTRAP_LOADED` - Bootstrap flag

## Integration with Other Plugins

When HyperFields is used as a Composer dependency:

**What to use:**
- `HyperFields\HyperFields` - For options pages and the `registerDataToolsPage()` facade
- `HyperFields\BlockFieldAdapter` - For block integration
- `HyperFields\Field` - For field definitions
- `HyperFields\Container\*` - For meta field containers
- `HyperFields\ExportImport` - For programmatic export / import
- `HyperFields\Admin\ExportImportUI` - For registering a Data Tools admin page

**What NOT to use:**
- `HyperFields\TemplateLoader` - Internal to HyperFields, auto-initialized

## Export / Import System

HyperFields ships a built-in Export / Import system for WordPress option groups.

### Registering a Data Tools page (recommended for third-party plugins)

Call inside `admin_menu`. One call handles menu registration, asset enqueueing, and rendering:

```php
add_action('admin_menu', function () {
    HyperFields\HyperFields::registerDataToolsPage(
        parentSlug: 'my-plugin',
        pageSlug:   'my-plugin-data-tools',
        options:    ['my_plugin_options' => 'My Plugin Settings'],
        allowedImportOptions: ['my_plugin_options'],
        prefix:     'myp_',
        title:      'Data Tools',
    );
});
```

Or using the procedural helper:

```php
add_action('admin_menu', function () {
    hf_register_data_tools_page(
        parentSlug: 'my-plugin',
        pageSlug:   'my-plugin-data-tools',
        options:    ['my_plugin_options' => 'My Plugin Settings'],
    );
});
```

### Programmatic API (no UI)

```php
// Export to JSON
$json = hf_export_options(['my_plugin_options'], 'myp_');

// Import from JSON (returns ['success' => bool, 'message' => string, 'backup_keys' => [...]])
$result = hf_import_options($json, ['my_plugin_options'], 'myp_');

// Restore from backup if import went wrong
if (!$result['success']) {
    HyperFields\ExportImport::restoreBackup($result['backup_keys']['my_plugin_options'], 'my_plugin_options');
}
```

### Key behaviours

- Export skips non-array option values (scalar options are not supported).
- Import is **additive**: existing keys not present in the payload are preserved.
- When `allowedImportOptions` / `$prefix` filtering removes all incoming entries, `importOptions` returns `success: false`.
- Before overwriting, `importOptions` stores a 1-hour transient backup; key returned in `backup_keys`.
- `restoreBackup` deletes the transient after a successful or no-op restore.
- `JSON_HEX_TAG | JSON_HEX_AMP` flags prevent XSS when the diff preview embeds JSON in `<script>` tags.

## Important Notes

- PHP 8.2+ required
- WordPress 5.0+ required
- Uses PSR-4 autoloading
- Optimized for production with `--optimize-autoloader`
- No external dependencies (pure WordPress)
- Backward-compatible with legacy `HMApi\` class names
- Library-only in this repository (no plugin entrypoint)
