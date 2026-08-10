# HyperFields

HyperFields is a Composer library for WordPress custom fields.

It provides:
- options pages
- post/user/term field containers
- field validation/sanitization
- conditional logic
- JSON export/import for options with typed-node schema validation
- JSON export/import for pages/CPT content
- pluggable transfer-module orchestration
- transfer audit logging with a built-in admin logs screen

## Installation

```bash
composer require estebanforge/hyperfields
```

Load your project Composer autoloader, then call the library bootstrap:

```php
require_once __DIR__ . '/vendor/autoload.php';

if (class_exists('\HyperFields\LibraryBootstrap')) {
    \HyperFields\LibraryBootstrap::init([
        'plugin_file' => __FILE__,
        'plugin_url'  => plugin_dir_url(__FILE__) . 'vendor/estebanforge/hyperfields/',
    ]);
}
```

HyperFields ships a `bootstrap.php` (registered under Composer `autoload.files`)
that *attempts* to self-initialize at `after_setup_theme`. That auto-bootstrap
is best-effort: it can silently no-op when the autoloader is pulled in before
`add_action()` exists (for example, by an early drop-in or must-use plugin),
leaving `Config` and the supporting subsystems uninitialized. Calling
`LibraryBootstrap::init()` explicitly after your autoloader is the supported,
deterministic contract. It is idempotent and safe under the cross-copy election
guard. See [`docs/library-bootstrap.md`](docs/library-bootstrap.md) for the
full guide, arguments, and failure modes.

## Basic usage

```php
use HyperFields\Field;
use HyperFields\OptionsPage;

$page = OptionsPage::make('My Settings', 'my-settings');

$page->addField(
    Field::make('text', 'site_title', 'Site Title')
        ->setDefault('My Site')
        ->setRequired()
);

$page->register();
```

## Helper functions

Procedural helpers are available with `hf_` prefix (for example: `hf_field`, `hf_get_field`, `hf_update_field`, `hf_option_page`).

## Schema validation for JSON imports

JSON exports now include embedded type schemas alongside each value. When importing, HyperFields validates that values match their declared schemas, preventing malformed data or injection attacks.

See [`docs/transfer-export-import.md`](docs/transfer-export-import.md) for:
- Typed-node envelope format
- SchemaValidator API
- Building schema maps for exports
- Import validation flow
- Extending with custom format validators
- Transfer audit logging, retention controls, and logs UI hooks

## Requirements

- PHP 8.1+

## Testing

HyperFields uses Pest v4.

```bash
composer run test
composer run test:unit
composer run test:integration
composer run test:coverage
composer run test:xdebug
```

## License

GPL-2.0-or-later
