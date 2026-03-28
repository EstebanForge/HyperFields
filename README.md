# HyperFields

A powerful custom field system for WordPress, providing metaboxes, options pages, conditional logic, and JSON export/import.

## Installation

### As a Composer Library

```bash
composer require estebanforge/hyperfields
```

Then include the bootstrap file in your project:

```php
require_once 'path/to/hyperfields/bootstrap.php';
```

## Usage

### Helper Functions

HyperFields provides convenient helper functions with the `hf_` prefix:

```php
// Create a field
$field = hf_field('text', 'my_field', 'My Field');

// Get field value
$value = hf_get_field('my_field', 'option', ['option_group' => 'my_options']);

// Update field value
hf_update_field('my_field', 'new value', 'option', ['option_group' => 'my_options']);

// Create an options page
$page = hf_option_page('My Settings', 'my-settings');
```

### Creating Fields

```php
use HyperFields\Field;
use HyperFields\OptionsPage;

// Create an options page
$page = OptionsPage::make('My Settings', 'my-settings');

// Add fields
$page->addField(
    Field::make('text', 'site_title', 'Site Title')
        ->setDefault('My Awesome Site')
        ->setRequired()
);

// Register the page
$page->register();
```

## Field Types

- text
- textarea
- number
- email
- url
- color
- date
- datetime
- time
- image
- file
- select
- multiselect
- checkbox
- radio
- radio_image
- rich_text
- hidden
- html
- map
- oembed
- separator
- header_scripts
- footer_scripts
- set
- sidebar
- association
- tabs
- custom
- heading
- media_gallery

## Export / Import

HyperFields includes a built-in Export/Import system for WordPress options. It lets developers provide a UI where site administrators can back up and restore plugin settings as JSON files.

### Register a Data Tools page (recommended)

One call inside `admin_menu` handles everything — menu registration, asset enqueueing, and rendering:

```php
add_action('admin_menu', function () {
    HyperFields\HyperFields::registerDataToolsPage(
        parentSlug: 'my-plugin',
        pageSlug:   'my-plugin-data-tools',
        options: [
            'my_plugin_options' => 'My Plugin Settings',
        ],
        allowedImportOptions: ['my_plugin_options'],
        prefix:     'myp_',
        title:      'Data Tools',
    );
});
```

Or with the procedural helper:

```php
add_action('admin_menu', function () {
    hf_register_data_tools_page(
        parentSlug: 'my-plugin',
        pageSlug:   'my-plugin-data-tools',
        options:    ['my_plugin_options' => 'My Plugin Settings'],
    );
});
```

### Use the API directly (no UI)

```php
// Export one or more option groups to a JSON string
$json = hf_export_options(['my_plugin_options'], 'myp_');

// Import from a JSON string (restrict to your own option only)
$result = hf_import_options($json, ['my_plugin_options'], 'myp_');
if ($result['success']) {
    // done; backup transient keys in $result['backup_keys'] if data existed
}

// Restore from a backup transient if needed
HyperFields\ExportImport::restoreBackup($result['backup_keys']['my_plugin_options'], 'my_plugin_options');
```

## Features

- Conditional logic
- Validation
- Sanitization
- Multiple storage types (post meta, user meta, term meta, options)
- Custom field containers
- Repeater fields
- Tabbed interfaces
- JSON export/import with visual diff preview
- Extensive hooks and filters

## Requirements

- PHP 8.1+
- WordPress 5.0+

## License

GPL-2.0-or-later
