# HyperFields Transfer Extensions

This document describes the generic, extensible transfer features added to HyperFields.

Goal:
- Keep HyperFields framework-agnostic.
- Support option-backed and content-backed portability.
- Allow downstream libraries/apps to plug in custom modules without forking HyperFields.

## 1) Extended Options Export/Import

Class: `HyperFields\ExportImport`

### Typed-node format

Every exported option is wrapped in a **typed-node envelope** — a JSON object containing the raw value alongside a `_schema` descriptor that declares what the value should look like. This makes export files self-describing and allows import-time validation without external schema files.

```json
{
  "version": "1.0",
  "type": "hyperfields_export",
  "prefix": "",
  "exported_at": "2026-03-31 12:00:00",
  "site_url": "https://example.com",
  "options": {
    "my_plugin_color": {
      "value": "#7f54b3",
      "_schema": {
        "type": "string",
        "format": "hex_color"
      }
    },
    "my_plugin_settings": {
      "value": {
        "enabled": "yes",
        "recipients": "admin@example.com"
      },
      "_schema": {
        "type": "array",
        "fields": {
          "enabled":    { "type": "string", "enum": ["yes", "no"] },
          "recipients": { "type": "string", "max": 2000, "format": "email_csv" }
        }
      }
    }
  }
}
```

On import, HyperFields **rejects** any option node that is missing the typed-node envelope (`value` + `_schema`). The value is validated against all `_schema` rules before being written to the database.

### Exporting with schema rules

Pass an optional `$schemaMap` to `exportOptions()` to embed full validation rules in each option's `_schema`. When no schema entry exists for an option, only the auto-detected PHP type is recorded.

```php
use HyperFields\ExportImport;

// Auto-detected types only (basic safety)
$json = ExportImport::exportOptions(['my_plugin_options']);

// Full schema rules embedded in the export
$json = ExportImport::exportOptions(
    ['my_plugin_color', 'my_plugin_name', 'my_plugin_settings'],
    '',  // prefix
    [
        'my_plugin_color' => [
            'type'   => 'string',
            'format' => 'hex_color',
        ],
        'my_plugin_name' => [
            'type' => 'string',
            'max'  => 255,
        ],
        'my_plugin_settings' => [
            'type'   => 'array',
            'fields' => [
                'enabled'    => ['type' => 'string', 'enum' => ['yes', 'no']],
                'recipients' => ['type' => 'string', 'max' => 2000, 'format' => 'email_csv'],
            ],
        ],
    ]
);
```

### Importing (validates automatically)

```php
$result = ExportImport::importOptions(
    $json,
    ['my_plugin_color', 'my_plugin_name', 'my_plugin_settings'],
    '',
    ['mode' => 'replace']
);

if ($result['success']) {
    // All values passed schema validation and were written.
}
```

Import validation is automatic. Every option node in the JSON file must:

1. Have a `value` key and a `_schema` object.
2. Declare a valid `_schema.type` (`string`, `integer`, `double`, `boolean`, `array`, or `null`).
3. Have a `value` whose actual PHP type matches the declared type.
4. Pass all additional schema rules (`max`, `min`, `enum`, `pattern`, `format`, `fields`).

Options that fail validation are rejected with a descriptive error message. The import continues processing remaining options.

### Dry-run diff

```php
$diff = ExportImport::diffOptions(
    $json,
    ['my_plugin_color', 'my_plugin_settings'],
    '',
    ['mode' => 'replace']
);

// $diff['changes'] — options that would change
// $diff['skipped'] — options skipped (validation errors, whitelist misses)
```

Diff applies the same typed-node validation as import. Invalid nodes appear in `skipped` with an error message.

### Import modes

- `merge` (default): incoming array keys are merged into the existing option value. Existing keys not present in the import are preserved.
- `replace`: incoming value replaces the stored value entirely.

### Notes

- Prefix filters apply to array keys only. Scalar option values are skipped when a non-empty prefix is provided.
- Backups use transient keys (`backup_keys`) with 1-hour expiry. Restore via `ExportImport::restoreBackup($key, $optionName)`.
- The `hyperfields/import/after` action fires after import completes, passing `($result, $decoded, $allowedOptionNames, $prefix, $options)`.

---

## 2) Schema Validator

Class: `HyperFields\Validation\SchemaValidator`

A standalone validation engine for arbitrary PHP values. Does not require a bound Field instance — usable anywhere: import validation, REST API endpoints, form processing, CLI commands.

### Schema rule format

A schema rule is an associative array describing the expected shape of a value:

```php
[
    'type'    => 'string',          // Required. PHP type.
    'max'     => 255,               // Max string length (mb_strlen).
    'min'     => 1,                 // Min string length (mb_strlen).
    'pattern' => '/^[a-z0-9-]+$/', // PCRE regex the value must match.
    'enum'    => ['yes', 'no'],     // Whitelist of allowed values.
    'format'  => 'email',           // Semantic format (see table below).
    'fields'  => [ ... ],           // Sub-schema for array values.
]
```

All keys except `type` are optional.

### Allowed types

| Type | PHP equivalent |
|---|---|
| `string` | `is_string()` |
| `integer` | `is_int()` |
| `double` | `is_float()` |
| `boolean` | `is_bool()` |
| `array` | `is_array()` |
| `null` | `is_null()` |

`null` values always pass validation regardless of declared type (the option may not exist on the source site).

### Supported formats

| Format | Description |
|---|---|
| `email` | Valid email address (non-empty). |
| `email_or_empty` | Valid email or empty string. |
| `email_csv` | Comma-separated list of valid emails (non-empty). |
| `email_csv_or_empty` | Comma-separated emails or empty string. |
| `hex_color` | CSS hex colour: `#rrggbb` (6 digits, case-insensitive). |
| `url` | Valid URL (non-empty). |
| `url_or_empty` | Valid URL or empty string. |

Custom formats can be registered via the `hyperfields/validation/format` filter:

```php
add_filter('hyperfields/validation/format', function (?string $error, string $fieldName, string $value, string $format): ?string {
    if ($format === 'slug' && !preg_match('/^[a-z0-9-]+$/', $value)) {
        return sprintf('"%s" is not a valid slug.', $fieldName);
    }
    return $error;
}, 10, 4);
```

### API

#### Validate a single value

```php
use HyperFields\Validation\SchemaValidator;

$error = SchemaValidator::validate('email_from', $value, [
    'type'   => 'string',
    'max'    => 320,
    'format' => 'email',
]);

if ($error !== null) {
    // $error is a human-readable string like:
    // '"email_from" is not a valid email address.'
}
```

#### Validate a map of values

```php
$settings = [
    'enabled'    => 'yes',
    'color'      => '#ff0000',
    'recipients' => 'a@b.com, c@d.com',
];

$schema = [
    'enabled'    => ['type' => 'string', 'enum' => ['yes', 'no']],
    'color'      => ['type' => 'string', 'format' => 'hex_color'],
    'recipients' => ['type' => 'string', 'max' => 2000, 'format' => 'email_csv'],
];

$errors = SchemaValidator::validateMap($settings, $schema);
// [] — empty array means all values are valid.

// With prefix for error messages:
$errors = SchemaValidator::validateMap($settings, $schema, 'my_plugin');
// Error messages like: '"my_plugin.color" must be a hex colour (#rrggbb)...'
```

#### Boolean shorthand

```php
if (SchemaValidator::isValid($value, ['type' => 'string', 'format' => 'email'])) {
    // Value is a valid email string.
}
```

#### Detect type

```php
$type = SchemaValidator::detectType($value);
// Returns: 'string', 'integer', 'double', 'boolean', 'array', or 'null'
```

#### Nested array validation

Use `fields` to validate the structure of an array value:

```php
$schema = [
    'type'   => 'array',
    'fields' => [
        'enabled'    => ['type' => 'string', 'enum' => ['yes', 'no']],
        'subject'    => ['type' => 'string', 'max' => 500],
        'email_type' => ['type' => 'string', 'enum' => ['html', 'plain', 'multipart']],
    ],
];

$error = SchemaValidator::validate('new_order_settings', $settings, $schema);
```

Fields not present in the `fields` sub-schema are ignored (pass-through). Fields present in `fields` but absent from the value are also ignored. Only fields that exist in both are validated.

### Helper functions

Procedural wrappers are available for quick use without importing the class:

```php
// Validate a single value
$error = hf_validate_value('field_name', $value, ['type' => 'string', 'max' => 255]);

// Validate a map
$errors = hf_validate_schema($values, $schemaMap, 'prefix');

// Boolean check
$ok = hf_is_valid($value, ['type' => 'string', 'format' => 'email']);

// Detect type
$type = hf_detect_type($value);
```

---

## 3) Practical Example: Building a Config Module

This example shows how a WordPress plugin would use HyperFields to export and import its settings with full schema validation.

### Define your schema

```php
class MyPluginSettings
{
    private const SCHEMA = [
        'my_plugin_api_url'    => ['type' => 'string', 'max' => 2083, 'format' => 'url'],
        'my_plugin_api_key'    => ['type' => 'string', 'max' => 255],
        'my_plugin_enabled'    => ['type' => 'string', 'enum' => ['yes', 'no']],
        'my_plugin_max_retries'=> ['type' => 'string', 'pattern' => '/^\d{1,2}$/'],
        'my_plugin_color'      => ['type' => 'string', 'format' => 'hex_color'],
        'my_plugin_recipients' => ['type' => 'string', 'max' => 2000, 'format' => 'email_csv_or_empty'],
    ];

    public function export(): string
    {
        return ExportImport::exportOptions(
            array_keys(self::SCHEMA),
            '',
            self::SCHEMA
        );
    }

    public function import(string $json): array
    {
        // HyperFields validates every option against its _schema automatically.
        return ExportImport::importOptions(
            $json,
            array_keys(self::SCHEMA),
            '',
            ['mode' => 'replace']
        );
    }

    public function validate_before_save(array $values): array
    {
        // Use SchemaValidator directly for form/REST validation.
        return SchemaValidator::validateMap($values, self::SCHEMA);
    }
}
```

### What happens on import

1. HyperFields parses the JSON and finds `my_plugin_api_url`.
2. It checks for a typed-node envelope (`value` + `_schema`). Missing? Rejected.
3. It reads `_schema.type` = `string` and confirms the value is a PHP string.
4. It reads `_schema.format` = `url` and validates the URL format.
5. It reads `_schema.max` = `2083` and checks string length.
6. All checks pass? The value is written via `update_option()`.
7. Any check fails? The option is skipped with a descriptive error, and import continues.

### Server-side form validation

You can also use SchemaValidator directly for validating user input on settings pages, REST endpoints, or anywhere else:

```php
// In your settings save handler:
$errors = hf_validate_schema($_POST['settings'], MyPluginSettings::SCHEMA);

if (!empty($errors)) {
    foreach ($errors as $error) {
        add_settings_error('my_plugin', 'validation', $error);
    }
    return;
}

// All valid — save.
```

---

## 4) Generic Pages/CPT Export/Import

Class: `HyperFields\ContentExportImport`

This is a generic content portability utility for post-like records (pages + CPT).

### Export

```php
use HyperFields\ContentExportImport;

$json = ContentExportImport::exportPosts(
    ['page', 'my_cpt'],
    [
        'post_status' => ['publish', 'draft', 'private'],
        'include_meta' => true,
        'include_private_meta' => false,
        'include_meta_keys' => [],      // optional allowlist
        'exclude_meta_keys' => ['_edit_lock'],
        'include_content' => true,
        'include_excerpt' => true,
        'include_parent' => true,
    ]
);
```

### Snapshot (for external compare workflows)

```php
$snapshot = ContentExportImport::snapshotPosts(['page', 'my_cpt']);
```

### Import

```php
$result = ContentExportImport::importPosts(
    $json,
    [
        'allowed_post_types' => ['page', 'my_cpt'],
        'dry_run' => false,
        'create_missing' => true,
        'update_existing' => true,
        'include_meta' => true,
        'meta_mode' => 'merge', // 'merge' | 'replace'
        'include_private_meta' => false,
        'include_meta_keys' => [],
        'exclude_meta_keys' => ['_edit_lock'],
    ]
);
```

### Dry-run diff

```php
$preview = ContentExportImport::diffPosts($json, [
    'allowed_post_types' => ['page', 'my_cpt'],
]);
```

Import matching rules:
- Canonical identity = `post_type + slug`.
- Existing records are resolved by `get_page_by_path($slug, OBJECT, $post_type)`.

---

## 5) Extensible Transfer Module Registry

Class: `HyperFields\Transfer\Manager`

Use this when you need HyperFields as the base transfer library, while composing project-specific modules externally.

### Register modules

```php
use HyperFields\Transfer\Manager;

$manager = new Manager();

$manager->registerModule(
    'options',
    exporter: function (array $context): array {
        return ['json' => \HyperFields\ExportImport::exportOptions($context['option_names'] ?? [])];
    },
    importer: function (array $payload, array $context): array {
        return \HyperFields\ExportImport::importOptions(
            (string) ($payload['json'] ?? ''),
            $context['allowed_options'] ?? []
        );
    },
    differ: function (array $payload, array $context): array {
        return \HyperFields\ExportImport::diffOptions(
            (string) ($payload['json'] ?? ''),
            $context['allowed_options'] ?? []
        );
    }
);
```

### Export/import/diff bundles

```php
$bundle = $manager->export();          // or export(['options'])
$diff   = $manager->diff($bundle);
$apply  = $manager->import($bundle);
```

Default bundle shape:

```json
{
  "schema_version": 1,
  "type": "hyperfields_transfer_bundle",
  "generated_at": "...",
  "modules": {
    "options": {}
  },
  "errors": []
}
```

### Custom export envelope (SchemaConfig)

Class: `HyperFields\Transfer\SchemaConfig`

By default the export envelope uses HyperFields type identifiers and schema version 1. Use `SchemaConfig` to override any of these values and inject additional top-level keys (e.g. `site`, `environment`, `app_version`) without modifying HyperFields itself.

```php
use HyperFields\Transfer\Manager;
use HyperFields\Transfer\SchemaConfig;

$manager = (new Manager())->withSchema(new SchemaConfig(
    type: 'my_plugin_manifest',
    schema_version: 2,
    extra: [
        'site' => [
            'url'         => get_site_url(),
            'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production',
        ],
    ],
));

$bundle = $manager->export();
```

Resulting bundle shape:

```json
{
  "site": {
    "url": "https://example.com",
    "environment": "staging"
  },
  "schema_version": 2,
  "type": "my_plugin_manifest",
  "generated_at": "...",
  "modules": {
    "options": {}
  },
  "errors": []
}
```

`withSchema()` returns the same `Manager` instance for fluent chaining. Calling it is optional — omitting it preserves the original default envelope.

**Reserved keys** — the following keys may not be overridden via `extra` and are silently stripped if present: `schema_version`, `type`, `generated_at`, `modules`, `errors`.

---

## 6) Public Facade + Helper Functions

### Facade (`HyperFields\HyperFields`)

- `HyperFields::diffOptions(...)`
- `HyperFields::exportPosts(...)`
- `HyperFields::snapshotPosts(...)`
- `HyperFields::importPosts(...)`
- `HyperFields::diffPosts(...)`
- `HyperFields::makeTransferManager()`

### Helpers (`includes/helpers.php`)

**Export/Import:**
- `hf_export_options(array $optionNames, string $prefix = '', array $schemaMap = []): string`
- `hf_import_options(string $json, array $allowed = [], string $prefix = '', array $options = []): array`
- `hf_diff_options(string $json, array $allowed = [], string $prefix = '', array $options = []): array`

**Content:**
- `hf_export_posts(array $postTypes, array $options = []): string`
- `hf_snapshot_posts(array $postTypes, array $options = []): array`
- `hf_import_posts(string $json, array $options = []): array`
- `hf_diff_posts(string $json, array $options = []): array`

**Schema Validation:**
- `hf_validate_value(string $fieldName, mixed $value, array $rule): ?string`
- `hf_validate_schema(array $values, array $schemaMap, string $prefix = ''): array`
- `hf_is_valid(mixed $value, array $rule): bool`
- `hf_detect_type(mixed $value): string`

---

## 7) Hooks Reference

| Hook | Type | Fired by | Parameters |
|---|---|---|---|
| `hyperfields/import/after` | Action | `ExportImport::importOptions()` | `$result, $decoded, $allowedOptionNames, $prefix, $options` |
| `hyperfields/validation/format` | Filter | `SchemaValidator` | `$error, $fieldName, $value, $format` — return string to reject, null to accept |
| `hyperfields/export/filename_prefix` | Filter | `ExportImportUI` | `$prefix` — customise the download filename |

---

## 8) Extension Guidance

Recommended pattern for downstream integrations:

1. Keep HyperFields generic and reusable.
2. Define your schema as a PHP constant or config array in your plugin.
3. Pass the schema to `exportOptions()` so it travels with the export file.
4. On import, HyperFields validates each value against its `_schema` automatically.
5. For additional domain-specific validation, use `SchemaValidator::validate()` or `SchemaValidator::validateMap()` in your module's import logic.
6. Register project-specific transfer modules in your application/plugin layer using `Transfer\Manager`.
7. Use `diffOptions()` / `diffPosts()` for safe dry-run previews before import.
8. Register custom format validators via the `hyperfields/validation/format` filter.
