# Project Overview

HyperFields is a decoupled WordPress library that provides a comprehensive custom field system. It is used as a Composer dependency for other plugins (like HyperPress).

**Package**: `estebanforge/hyperfields`
**Repository**: https://github.com/EstebanForge/HyperFields

## Installation

### As Composer Dependency
```bash
composer require estebanforge/hyperfields
```

### Loading inside a host plugin

HyperFields self-initializes via its `bootstrap.php` (a Composer
`autoload.files` entry) behind a first-to-boot guard, so loading the host
plugin's own autoloader is all that is required:

```php
// my-plugin.php
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
```

To pin a specific copy or override URL detection, call
`LibraryBootstrap::init()` explicitly with `plugin_file` and `plugin_url`. See
[docs/library-bootstrap.md](docs/library-bootstrap.md) for the full guide
(standard flat-vendor, `plugins_loaded` class pattern, and monorepo layouts).

### Bedrock / Composer-managed WordPress sites

When this library is installed **transitively** into a Bedrock-style project, Composer places it in the project **root `vendor/`** (outside `wp-content/`), because the package is `type: library` and Bedrock's `installer-paths` only route `wordpress-plugin` / `wordpress-muplugin` / `wordpress-theme` types. That root-vendor copy is not under any web-accessible WordPress content root, so `HyperFields\LibraryBootstrap::resolveContentUrl()` returns `''` for it and its admin/field assets cannot be served over HTTP.

The library still initializes normally from such a copy — it does **not** gate boot on web-reachability. `Config::$pluginUrl` is simply empty, and the asset enqueue bails gracefully (`Assets.php` early-returns on an empty URL) instead of emitting a 404; server-side functionality (fields, options pages, export/import) is unaffected. The bootstrap scheduler runs before the `ABSPATH` guard, so a root-vendor copy initializes reliably even in Bedrock's `wp-config` load order: when `add_action()` is not yet available it writes the `after_setup_theme` registration straight into `$GLOBALS['wp_filter']` in the preinitialized-hooks format. For assets to serve, the library must be loaded from a web-reachable copy; on a dual-copy Bedrock site (root `vendor/` plus a plugin-bundled copy) remove the root copy via Composer `replace` + `composer update --lock` (never `rm`) so the web-reachable plugin-bundled copy wins the `autoload.files` race.

**Recommended pattern for plugins that bundle HyperFields:** ship it inside the plugin's own committed `vendor/` (e.g. `wp-content/plugins/<your-plugin>/vendor/estebanforge/hyperfields/`) and load the plugin's own `vendor/autoload.php`. That copy is web-reachable, so `Config::$pluginUrl` resolves and assets enqueue. Do not rely on a Bedrock root-vendor copy to serve assets; it never can.

## Development Commands

### Composer Commands
```bash
composer dump-autoload --optimize  # Regenerate optimized autoloader
composer install --no-dev --optimize-autoloader  # Production install
composer update --no-dev --optimize-autoloader   # Production update
```

### Version Management
- Update version in `composer.json` (canonical source)
- `src/Config.php` `VERSION` constant mirrors it for the PHP side — run `composer version-bump` to update both atomically
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
  Admin/               # Activation, options pages, export/import UI, logs
  Compatibility/       # wp-settings parity / dual-write store layer
  Container/           # Field containers (PostMeta, TermMeta, UserMeta)
  Transfer/            # Transfer orchestration + audit logging
  Validation/          # SchemaValidator for JSON imports
  templates/           # Field UI templates
  Assets.php           # Asset management
  BlockFieldAdapter.php # Gutenberg integration
  Config.php           # VERSION constant + runtime path/URL (prefix-safe)
  ExportImport.php     # Export / Import core logic
  Field.php            # Base field class
  HyperFields.php      # Main API class
  LibraryBootstrap.php # Library entry point: init() (prefixable)
  Registry.php         # Field registration
  TemplateLoader.php   # Template rendering system
  helpers.php          # Procedural helpers (hf_* prefix)
bootstrap.php         # Dev-env auto-init bridge (delegates to LibraryBootstrap::init())
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

## Cache Invalidation (transients + OPcache)

`HyperFields\CacheInvalidator` keeps cached representations of HyperFields data coherent after a save. It hooks the semantic save actions (`hyperfields/options_page/after_save`, `hyperfields/settings/after_save`, `hyperfields/{post,term,user}_meta_container_saved`, `hyperfields/import/after`), each of which fires only on a real value change, and clears:

- **Transients** - backend-aware: with no external object cache it deletes every `_transient_*` / `_site_transient_*` row from `wp_options` (and `wp_sitemeta` on a multisite main network); with a persistent object cache (Redis/Memcached) transients live only in the cache under the `transient` / `site-transient` groups, so it flushes those groups surgically via `wp_cache_flush_group()` instead.
- **OPcache** - `opcache_reset()` when the extension is available.

Wired in automatically by `LibraryBootstrap::init()`. Requires WordPress 6.5+ (guarantees `wp_cache_flush_group()`).

**Filters:**
- `hyperfields/cache/auto_invalidate` (master switch, default `true`)
- `hyperfields/cache/flush_transients` (default `true`)
- `hyperfields/cache/flush_object_cache` (default `false`) - opt-in full `wp_cache_flush()`, the documented anti-pattern; use only on a persistent backend that lacks group-flush support.
- `hyperfields/cache/reset_opcache` (default `true`)

Disable everything:
```php
add_filter('hyperfields/cache/auto_invalidate', '__return_false');
```

Manual flush (wp-cron, migrations, importers that bypass the save actions):
```php
HyperFields\CacheInvalidator::flush();
// or
hf_flush_hyperfields_cache();
```

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

HyperFields self-initializes via `HyperFields\LibraryBootstrap::init()`, which is
idempotent (guarded by `Config::isInitialized()`). When loaded directly through
Composer, `bootstrap.php` schedules `init()` at `after_setup_theme`; vendored
or namespace-prefixed consumers call `LibraryBootstrap::init()` explicitly.

Duplicate-load protection: the first copy to reach `init()` claims the
namespace-scoped `HyperFields\LOADED` constant and wins; later copies bail
before bootstrapping, so two plugins shipping HyperFields do not double-init or
fatal. This is a first-to-boot guard (not newest-wins, no version resolution,
no class-shadow guard). The guard is namespace-scoped, so a consumer that
optionally prefixes the namespace with [Mozart](https://github.com/coenjacobs/mozart) gets fully isolated
copies that each boot independently. See the repository for a ready-to-use
prefix config if you need version determinism across divergent copies.

Runtime paths live on `HyperFields\Config` (prefix-safe), not global constants:
- `Config::VERSION` — semantic version (mirrors `composer.json`)
- `Config::$abspath` — library root path, set at init
- `Config::$pluginUrl` — public URL, or empty when not web-reachable
- `Config::$pluginFile` — absolute path to the bootstrap file

HyperFields defines **no** `HYPERPRESS_*` constants; HyperPress owns those and
resolves them from its own bootstrap (no cross-plugin shared state).

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
- Imported values can be sanitized through registered fields via the `hyperfields/import/sanitize_fields` filter: return a map of `field name => HyperFields\Field` for the given option name, and every matching top-level key of the imported value runs through `Field::sanitizeValue()` before storage. Default (no filter) keeps values unchanged (since 1.5.5).
- The Data Tools diff preview and JSON viewer use libraries bundled under `assets/{js,css}/vendor/` (diff, diff2html, highlight.js theme, @textea/json-viewer), enqueued server-side. No CDN scripts load in the admin origin. When the library is not web-reachable the UI degrades to safe text-based fallbacks instead of fetching anything remotely (since 1.5.5).

## Important Notes

- PHP 8.2+ required
- WordPress 6.5+ required
- Uses PSR-4 autoloading
- Optimized for production with `--optimize-autoloader`
- No external dependencies (pure WordPress)
- Library-only in this repository (no plugin entrypoint)

## Abilities API module (1.7.0+)

`src/Abilities/AbilityRegistrar.php` registers the `hyperfields` category and three abilities over registered options pages: `hyperfields/list-option-pages` (inventory with per-field JSON Schema; `manage_options`), `hyperfields/get-option` and `hyperfields/update-option` (permission resolved per page from `OptionsPage::getCapability()` at execution time; unknown pages fail closed). Writes run through `OptionsPage::setFieldValue()`: the Settings-API save pipeline narrowed to one field (`wps_sanitize` -> `Field::sanitizeValue()` -> `wps_validate` -> `hyperfields/options_page/pre_save` -> `option_path` dual-write). Same-value writes are idempotent successes. The field inventory is request-scoped for pages with conditional sections: re-list after changing a dependency field.

Supporting API: `Field::toJsonSchema()` exports JSON Schema per field (formats, enums, number bounds, recursive repeater shapes); structural/UI types and non-derivable storage return `null`; `hyperfields/field/json_schema` is the escape hatch. `OptionsPage::getRegisteredPages()` / `findField()` / `allFields()` back the discovery surface.

Exposure contract: `hyperfields/abilities/enabled`, `hyperfields/abilities/expose_rest`, `hyperfields/abilities/mcp_public`. Default: registered everywhere, exposed nowhere.
