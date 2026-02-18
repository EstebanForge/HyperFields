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
  Admin/               # Activation, options pages, migrations
  Container/           # Field containers (PostMeta, TermMeta, UserMeta, Options)
  Templates/           # Field UI templates
  Assets.php          # Asset management
  BlockFieldAdapter.php  # Gutenberg integration
  ConditionalLogic.php   # Field visibility logic
  Field.php           # Base field class
  HyperFields.php     # Main API class
  Registry.php        # Field registration
  TemplateLoader.php  # Template rendering system
includes/
  helpers.php         # Helper functions
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
- `HyperFields\HyperFields` - For options pages
- `HyperFields\BlockFieldAdapter` - For block integration
- `HyperFields\Field` - For field definitions
- `HyperFields\Container\*` - For meta field containers

**What NOT to use:**
- `HyperFields\TemplateLoader` - Internal to HyperFields, auto-initialized

## Important Notes

- PHP 8.1+ required
- WordPress 5.0+ required
- Uses PSR-4 autoloading
- Optimized for production with `--optimize-autoloader`
- No external dependencies (pure WordPress)
- Backward-compatible with legacy `HMApi\` class names
- Library-only in this repository (no plugin entrypoint)
