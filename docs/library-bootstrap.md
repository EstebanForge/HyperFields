# LibraryBootstrap — Composer / Vendored Usage

`HyperFields\LibraryBootstrap::init()` is the entry point when HyperFields is
used as a Composer dependency inside another plugin rather than as a standalone
plugin itself.

## When to call it

Call it once, after your autoloader is loaded and before any HyperFields class
is used. The method is idempotent — repeated calls are no-ops.

During bootstrap, HyperFields also initializes transfer-audit logging hooks
automatically (`HyperFields\Transfer\AuditLogger`). No extra setup is required
to start recording export/import audit events.

```php
$autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (class_exists('\HyperFields\LibraryBootstrap')) {
    \HyperFields\LibraryBootstrap::init([
        'plugin_file' => __FILE__,
        'plugin_url'  => plugin_dir_url(__FILE__) . 'vendor/estebanforge/hyperfields/',
    ]);
}
```

## Duplicate-load protection

The first copy to reach `init()` claims the namespace-scoped
`HyperFields\LOADED` constant and wins; any later copy bails before
bootstrapping. So two plugins that both ship HyperFields do not double-init or
fatal. This is first-to-boot, not newest-wins. If you need guaranteed
isolation across divergent versions, prefix the namespace with
[Mozart](https://github.com/coenjacobs/mozart). A prefixed copy lives under a
different namespace and boots independently; see the HyperFields repository for
a ready-to-use config.

## Arguments

| Key | Type | Description |
|---|---|---|
| `plugin_file` | `string` | Absolute path to the **host** plugin's main file. Used as the base for URL resolution. |
| `plugin_url` | `string` | Public URL to the HyperFields library root (trailing slash). |
| `base_dir` | `string` | Absolute path to the HyperFields library root. Defaults to the directory containing `LibraryBootstrap.php`. |

## URL resolution and the web-reachability deferral

When `plugin_url` is omitted, `init()` calls `resolve_plugin_url()`, which
delegates to `HyperFields\LibraryBootstrap::resolveContentUrl()`. That resolver
walks the web-accessible WordPress content roots (`WP_PLUGIN_DIR`,
`WPMU_PLUGIN_DIR`, `WP_CONTENT_DIR`, and the active theme template/stylesheet
directories), canonicalising both the query path and each root with
`realpath()` / `wp_normalize_path()`, and returns the first root that prefixes
the library's `base_dir` plus the relative remainder as the URL. It returns
`''` when the library sits under none of them.

Since the deferral landed, `init()` no longer claims the namespace identity
when the URL cannot be resolved. When `resolve_plugin_url()` returns `''` and
no explicit `plugin_url` was passed, `init()` returns **without** defining
`HyperFields\LOADED` or writing `Config::$pluginUrl`, so a web-reachable copy
(e.g. one bundled inside a plugin under `wp-content/`) is free to reach
`init()` and claim the identity. The classic failure mode — a non-web-reachable
copy locking out a reachable one with an empty asset URL, leaving admin/field
assets unenqueued — no longer occurs as long as some reachable copy is loaded.

If **no** copy can resolve a URL and none passes an explicit `plugin_url`,
HyperFields never initializes, so its admin/field asset handles are never
registered and `wp_add_inline_style('hyperpress-admin', ...)` silently no-ops.
That is the fail-closed signal that the library is loaded from a location HTTP
cannot reach.

Pass explicit `plugin_file` and `plugin_url` args when you want to pin a
specific copy as the winner regardless of load order, or when the library
lives in a non-standard location whose URL the resolver cannot infer (the
explicit `plugin_url` overrides the deferral and forces the copy to claim the
identity).

## Examples

### Standard plugin (flat vendor directory)

The most common case: HyperFields vendored directly inside your plugin.

```
wp-content/plugins/my-plugin/
├── my-plugin.php
└── vendor/estebanforge/hyperfields/
```

```php
// my-plugin.php

$autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (class_exists('\HyperFields\LibraryBootstrap')) {
    \HyperFields\LibraryBootstrap::init([
        'plugin_file' => __FILE__,
        'plugin_url'  => plugin_dir_url(__FILE__) . 'vendor/estebanforge/hyperfields/',
    ]);
}
```

### Bootstrapping inside a class (plugins_loaded pattern)

When your plugin defers setup to a bootstrap class hooked on `plugins_loaded`,
pass the constants defined at the top of the main plugin file.

```php
// my-plugin.php

define('MY_PLUGIN_FILE', __FILE__);
define('MY_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    $autoload = plugin_dir_path(MY_PLUGIN_FILE) . 'vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (class_exists('\HyperFields\LibraryBootstrap')) {
        \HyperFields\LibraryBootstrap::init([
            'plugin_file' => MY_PLUGIN_FILE,
            'plugin_url'  => MY_PLUGIN_URL . 'vendor/estebanforge/hyperfields/',
        ]);
    }

    MyPlugin\Plugin::get_instance();
});
```

### Monorepo / Bedrock layout with symlinked plugins

In monorepos or Bedrock-style setups the `vendor` directory is often outside
the WP plugins directory, or the plugin directory itself is a symlink. Auto-
detection breaks here. Define constants from the host plugin's own known URL.

```
web/app/plugins/my-plugin/          ← registered with WP (may be a symlink)
packages/my-plugin/
├── my-plugin.php
└── vendor/estebanforge/hyperfields/
```

```php
// my-plugin.php — constants are safe because plugin_dir_url() resolves
// against WP's own plugin registration, not the filesystem path.

\HyperFields\LibraryBootstrap::init([
    'plugin_file' => __FILE__,
    'plugin_url'  => plugin_dir_url(__FILE__) . 'vendor/estebanforge/hyperfields/',
]);
```

### Using the Export/Import UI after bootstrapping

Once `LibraryBootstrap::init()` has run, `ExportImportUI` assets enqueue
correctly. Wire it from `admin_enqueue_scripts` on your specific page only.

```php
add_action('admin_menu', function () {
    $hook = add_submenu_page(
        'my-plugin',
        'Data Tools',
        'Data Tools',
        'manage_options',
        'my-plugin-data-tools',
        'my_plugin_render_data_tools_page'
    );

    add_action('admin_enqueue_scripts', function (string $suffix) use ($hook) {
        if ($suffix === $hook) {
            \HyperFields\Admin\ExportImportUI::enqueuePageAssets();
        }
    });
});

function my_plugin_render_data_tools_page(): void {
    echo \HyperFields\Admin\ExportImportUI::render(
        options: ['my_plugin_options' => 'My Plugin Settings'],
        title:   'Data Tools',
    );
}
```
