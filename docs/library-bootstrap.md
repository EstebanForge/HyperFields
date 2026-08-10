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

## Auto-bootstrap is best-effort

HyperFields ships a `bootstrap.php` (registered in the library's own
`composer.json` under `autoload.files`) that tries to self-initialize by
scheduling `LibraryBootstrap::init()` on `after_setup_theme`. When Composer's
files-autoload runs at a normal point in the WordPress load (an active plugin
requiring its `vendor/autoload.php` during the plugin-loading phase), this
works without any consumer code.

It can also silently fail, leaving `Config` and every subsystem uninitialized
while the classes themselves still autoload and appear to work:

- **Early autoloader inclusion.** If a drop-in (`object-cache.php`,
  `advanced-cache.php`), a must-use plugin, or `wp-config.php` pulls in a
  Composer autoloader before `wp-includes/plugin.php` loads, `bootstrap.php`
  runs before `add_action()` exists. Its `function_exists('add_action')` guard
  then skips the `after_setup_theme` registration, so `init()` is never
  scheduled.
- **No error is raised.** The `hyperfields_bootstrap_init` function is still
  defined and `Config::isInitialized()` stays `false`; the only outward signs
  are missing CSS/JS and dead subsystem features.

The asset layer self-heals regardless (see *URL resolution and graceful
degradation*), because every enqueue resolves its URL from the library's own
root when `Config::$pluginUrl` is empty. The subsystems (`Registry`, `Assets`,
`TemplateLoader`, `Transfer\AuditLogger`, `CacheInvalidator`) do **not**
self-heal; only an executed `init()` brings them up.

For this reason, calling `LibraryBootstrap::init()` explicitly after your
autoloader is the supported contract. It is idempotent, safe under the
cross-copy election guard, and removes all dependence on the auto-bootstrap
timing.

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

## URL resolution and graceful degradation

When `plugin_url` is omitted, `init()` calls `resolve_plugin_url()`, which
delegates to `HyperFields\LibraryBootstrap::resolveContentUrl()`. That resolver
walks the web-accessible WordPress content roots (`WP_PLUGIN_DIR`,
`WPMU_PLUGIN_DIR`, `WP_CONTENT_DIR`, and the active theme template/stylesheet
directories), canonicalising both the query path and each root with
`realpath()` / `wp_normalize_path()`, and returns the first root that prefixes
the library's `base_dir` plus the relative remainder as the URL. It returns
`''` when the library sits under none of them.

`init()` always runs (it does not gate boot on web-reachability): it claims the
namespace identity, loads the procedural API, and registers hooks regardless of
whether the URL resolves. When the copy is not web-reachable,
`Config::$pluginUrl` is simply empty.

Asset enqueues (`TemplateLoader`, `Assets`, `AdminPage`, `OptionsPage`, and
`Admin\ExportImportUI`) do not bail on an empty `Config::$pluginUrl`. They all
route through `LibraryBootstrap::resolveAssetBaseUrl()`, whose final tier
resolves the URL from the library's own root via `resolveContentUrl()`. Admin
and field CSS/JS therefore still enqueue as long as the library directory sits
under a web-accessible content root, even when `init()` never ran. When the
copy is genuinely not web-reachable (for example, a Bedrock root vendor outside
the document root), `resolveContentUrl()` returns `''` and the enqueues bail
rather than emit a 404ing URL.

Server-side functionality (the field registry, options pages, export/import,
cache invalidation, audit logging) is **not** available until `init()` has run:
`Registry`, `Assets`, `TemplateLoader`, `Transfer\AuditLogger`, and
`CacheInvalidator` are all initialized exclusively inside `init()`. So while the
asset layer self-heals, a copy whose `init()` never executed will render pages
with missing subsystem behavior. This is why the explicit `init()` call shown
above is the supported contract, not an optional convenience.

The `bootstrap.php` ABSPATH guard adds defense in depth: a root-vendor copy's
`bootstrap.php` returns early when included before `ABSPATH` is defined
(Bedrock loads the root autoloader in `wp-config`, before `ABSPATH` exists),
so it does not schedule a competing `init()` ahead of a plugin-bundled copy.

Pass explicit `plugin_file` and `plugin_url` args when you want to pin a
specific copy as the winner regardless of load order, or when the library
lives in a non-standard location whose URL the resolver cannot infer.

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
