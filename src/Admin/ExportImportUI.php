<?php

declare(strict_types=1);

namespace HyperFields\Admin;

use HyperFields\ExportImport;
use HyperFields\TemplateLoader;

/**
 * Reusable Export/Import UI component.
 *
 * Generates an admin page that lets users:
 *  1. Export selected option groups to a downloadable JSON file.
 *  2. Upload a JSON file and preview a visual diff of what will change.
 *  3. Confirm the import after reviewing the diff.
 *
 * The simplest integration is a single call to registerPage(), which handles
 * menu registration, asset enqueueing, and page rendering automatically:
 *
 * ```php
 * add_action('admin_menu', function () {
 *     \HyperFields\Admin\ExportImportUI::registerPage(
 *         parentSlug:           'my-plugin',
 *         pageSlug:             'my-plugin-data-tools',
 *         options:              ['my_plugin_options' => 'My Plugin Settings'],
 *         allowedImportOptions: ['my_plugin_options'],
 *         prefix:               'myp_',
 *         title:                'My Plugin – Data Tools',
 *     );
 * });
 * ```
 *
 * For manual control, call render() inside your own submenu page callback and
 * call enqueuePageAssets() from an admin_enqueue_scripts hook for the same page:
 *
 * ```php
 * add_action('admin_enqueue_scripts', function (string $hook) {
 *     if ($hook === 'my-plugin_page_my-plugin-data-tools') {
 *         \HyperFields\Admin\ExportImportUI::enqueuePageAssets();
 *     }
 * });
 *
 * add_action('admin_menu', function () {
 *     add_submenu_page(
 *         'my-plugin',
 *         'Data Tools', 'Data Tools', 'manage_options',
 *         'my-plugin-data-tools',
 *         function () {
 *             echo \HyperFields\Admin\ExportImportUI::render(
 *                 options:              ['my_plugin_options' => 'My Plugin Settings'],
 *                 allowedImportOptions: ['my_plugin_options'],
 *                 prefix:               'myp_',
 *                 title:                'My Plugin – Data Tools',
 *             );
 *         }
 *     );
 * });
 * ```
 */
class ExportImportUI
{
    /**
     * Register a submenu page and wire up all required hooks automatically.
     *
     * This is the recommended single-call API for third-party developers.
     * Call it from inside an `admin_menu` action hook.
     *
     * @param string $parentSlug           Parent menu slug (e.g. 'my-plugin' or 'options-general.php').
     * @param string $pageSlug             Unique slug for this page (e.g. 'my-plugin-data-tools').
     * @param array  $options              Associative map of WP option names to human-readable labels.
     *                                     Example: ['myplugin_options' => 'My Plugin Settings']
     * @param array  $allowedImportOptions Whitelist of option names that may be overwritten on import.
     *                                     Defaults to all keys in $options.
     * @param string $prefix               Optional key prefix applied to both export and import.
     * @param string $title                Page heading and menu label.
     * @param string $capability           Required capability. Default: 'manage_options'.
     * @param string $description          Short description shown below the heading.
     */
    public static function registerPage(
        string $parentSlug,
        string $pageSlug,
        array $options = [],
        array $allowedImportOptions = [],
        string $prefix = '',
        string $title = 'Data Export / Import',
        string $capability = 'manage_options',
        string $description = 'Export your settings to JSON or import a previously exported file.',
        ?callable $exporter = null,
        ?callable $previewer = null,
        ?callable $importer = null,
        ?string $exportFormExtras = null,
    ): void {
        $config = new ExportImportPageConfig(
            options: $options,
            allowedImportOptions: $allowedImportOptions,
            prefix: $prefix,
            title: $title,
            description: $description,
            exporter: $exporter,
            previewer: $previewer,
            importer: $importer,
            exportFormExtras: $exportFormExtras,
        );

        // Determine the hook suffix WordPress will assign to this page
        $parentBase   = str_replace('.php', '', basename($parentSlug));
        $hookSuffix   = ($parentBase === 'options-general' ? 'settings' : $parentBase) . '_page_' . $pageSlug;

        // Enqueue HyperFields admin CSS + diff assets on this page only
        add_action('admin_enqueue_scripts', static function (string $hook) use ($hookSuffix): void {
            if ($hook === $hookSuffix) {
                self::enqueuePageAssets();
            }
        });

        add_submenu_page(
            $parentSlug,
            $title,
            $title,
            $capability,
            $pageSlug,
            static function () use ($config): void {
                echo self::renderConfigured($config);
            }
        );
    }

    public static function renderConfigured(ExportImportPageConfig $config): string
    {
        return self::render(
            options:              $config->options,
            allowedImportOptions: $config->resolvedAllowedImportOptions(),
            optionGroups:         $config->optionGroups,
            prefix:               $config->prefix,
            title:                $config->title,
            description:          $config->description,
            exporter:             $config->exporter,
            previewer:            $config->previewer,
            importer:             $config->importer,
            exportFormExtras:     $config->exportFormExtras,
        );
    }

    /**
     * Enqueue all assets required by this page.
     *
     * Called automatically by registerPage(). When using render() manually,
     * call this from your own admin_enqueue_scripts hook for the correct page.
     */
    public static function enqueuePageAssets(): void
    {
        TemplateLoader::enqueueAssets();
        $pluginUrl = defined('HYPERPRESS_PLUGIN_URL') ? HYPERPRESS_PLUGIN_URL : (defined('HYPERFIELDS_PLUGIN_URL') ? HYPERFIELDS_PLUGIN_URL : '');
        $version   = defined('HYPERPRESS_VERSION') ? HYPERPRESS_VERSION : (defined('HYPERFIELDS_VERSION') ? HYPERFIELDS_VERSION : '0.0.0');

        if ($pluginUrl !== '') {
            wp_enqueue_script(
                'hyperpress-admin-options',
                $pluginUrl . 'assets/js/admin-options.js',
                [],
                $version,
                true
            );
        }

        self::enqueueDiffAssets();
    }

    /**
     * Render the complete export/import UI as an HTML string.
     *
     * Must be echo'd inside a WP admin page callback. Assets must be enqueued
     * separately via enqueuePageAssets() — use registerPage() to handle both
     * automatically.
     *
     * @param array  $options              Associative map of WP option names to human-readable labels.
     * @param array  $allowedImportOptions Whitelist of option names permitted to be overwritten on import.
     *                                     Defaults to all keys in $options.
     * @param string $prefix               Optional prefix filter applied to both export and import.
     * @param string $title                Page heading displayed at the top.
     * @param string $description          Short description shown below the heading.
     * @return string HTML ready to be echo'd inside a WP admin page callback.
     */
    /**
     * Render the complete export/import UI as an HTML string.
     *
     * @param array         $options              Associative map of WP option names to human-readable labels.
     * @param array         $allowedImportOptions Whitelist of option names permitted to be overwritten on import.
     *                                            Defaults to all keys in $options.
     * @param string        $prefix               Optional prefix filter applied to both export and import.
     * @param string        $title                Page heading displayed at the top.
     * @param string        $description          Short description shown below the heading.
     * @param callable|null $exporter             Optional custom export callable. Replaces ExportImport::exportOptions().
     *                                            Signature: fn(array $selectedNames, string $prefix): string
     *                                            Must return a JSON string ready for display/download.
     * @param callable|null $previewer            Optional custom preview/diff callable. Replaces handlePreview().
     *                                            Signature: fn(array $decoded, array $allowedImportOptions, string $prefix, array $options): array
     *                                            Must return: {success: bool, message?: string, transient_key?: string, current?: array, incoming?: array}
     *                                            The $decoded argument is the JSON-decoded upload payload (associative array).
     * @param callable|null $importer             Optional custom import callable. Replaces ExportImport::importOptions().
     *                                            Signature: fn(string $jsonString, array $allowedImportOptions, string $prefix): array
     *                                            Must return: {success: bool, message: string}
     * @param string|null $exportFormExtras       Optional raw HTML injected into the export form before `<p class="submit">`.
     * @return string HTML ready to be echo'd inside a WP admin page callback.
     */
    public static function render(
        array $options = [],
        array $allowedImportOptions = [],
        array $optionGroups = [],
        string $prefix = '',
        string $title = 'Data Export / Import',
        string $description = 'Export your settings to JSON or import a previously exported file.',
        ?callable $exporter = null,
        ?callable $previewer = null,
        ?callable $importer = null,
        ?string $exportFormExtras = null,
    ): string {
        if (empty($allowedImportOptions)) {
            $allowedImportOptions = array_keys($options);
        }

        // ---------- Handle: Export ----------
        $exportJson  = '';
        $exportError = '';
        if (
            isset($_POST['hf_export_submit'])
            && isset($_POST['hf_export_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hf_export_nonce'])), 'hf_export_action')
        ) {
            $selectedNames = isset($_POST['hf_export_options']) && is_array($_POST['hf_export_options'])
                ? array_map('sanitize_text_field', array_map('strval', wp_unslash($_POST['hf_export_options'])))
                : [];

            $selectedNames = array_values(array_intersect($selectedNames, array_keys($options)));

            if (empty($selectedNames)) {
                $exportError = __('Please select at least one option group to export.', 'hyperfields');
            } elseif ($exporter !== null) {
                $result = call_user_func($exporter, $selectedNames, $prefix);
                $exportJson = is_string($result) ? $result : '';
                if ($exportJson === '') {
                    $exportError = __('The custom exporter returned an empty result.', 'hyperfields');
                }
            } else {
                $exportJson = ExportImport::exportOptions($selectedNames, $prefix);
            }
        }

        // ---------- Handle: Preview upload ----------
        $previewTransientKey = '';
        $previewError        = '';
        $currentSnapshot     = [];
        $incomingData        = [];
        if (
            isset($_POST['hf_preview_submit'])
            && isset($_POST['hf_preview_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hf_preview_nonce'])), 'hf_preview_action')
            && isset($_FILES['hf_import_file'])
            && is_array($_FILES['hf_import_file'])
        ) {
            $file          = $_FILES['hf_import_file'];
            $previewResult = self::handlePreview($file, $allowedImportOptions, $prefix, $options, $previewer);

            if ($previewResult['success']) {
                $previewTransientKey = $previewResult['transient_key'];
                $currentSnapshot     = $previewResult['current'];
                $incomingData        = $previewResult['incoming'];
            } else {
                $previewError = $previewResult['message'];
            }
        }

        // ---------- Handle: Confirm import ----------
        $importMessage = '';
        $importSuccess = false;
        if (
            isset($_POST['hf_confirm_submit'])
            && isset($_POST['hf_confirm_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hf_confirm_nonce'])), 'hf_confirm_action')
        ) {
            $transientKey = isset($_POST['hf_transient_key'])
                ? sanitize_text_field(wp_unslash($_POST['hf_transient_key']))
                : '';

            $storedJson = $transientKey ? get_transient($transientKey) : false;

            if ($storedJson && is_string($storedJson)) {
                $result = $importer !== null
                    ? call_user_func($importer, $storedJson, $allowedImportOptions, $prefix)
                    : ExportImport::importOptions($storedJson, $allowedImportOptions, $prefix);
                $importSuccess = (bool) ($result['success'] ?? false);
                $importMessage = (string) ($result['message'] ?? '');
                delete_transient($transientKey);
            } else {
                $importMessage = __('Import session expired or is invalid. Please upload the file again.', 'hyperfields');
            }
        }

        ob_start();
        self::renderHtml(
            title:               $title,
            description:         $description,
            options:             $options,
            optionGroups:        $optionGroups,
            prefix:              $prefix,
            exportJson:          $exportJson,
            exportError:         $exportError,
            previewTransientKey: $previewTransientKey,
            previewError:        $previewError,
            currentSnapshot:     $currentSnapshot,
            incomingData:        $incomingData,
            importMessage:       $importMessage,
            importSuccess:       $importSuccess,
            exportFormExtras:    $exportFormExtras,
        );

        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Enqueue shared UI styles via the WordPress asset pipeline.
     *
     * Always safe to call — wp_enqueue_* deduplicates automatically.
     */
    private static function enqueueDiffAssets(): void
    {
        if (function_exists('wp_add_inline_style')) {
            wp_add_inline_style('hyperpress-admin', <<<CSS
textarea.hf-json-codeblock {
    background: #0f172a;
    border: 1px solid #1f2937;
    border-radius: 8px;
    color: #e5e7eb;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    font-size: 14px;
    line-height: 1.45;
    padding: 16px;
}
textarea.hf-json-codeblock:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 1px #2563eb;
}
.hf-json-copy-wrap {
    position: relative;
    display: block;
    width: 100%;
    overflow: hidden;
    border-radius: 8px;
}
.hf-json-copy-button.is-copied {
    border-color: #00a32a;
    color: #00a32a;
}
.hf-export-options-toolbar-row {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 16px;
    flex-wrap: wrap;
    width: 100%;
}
.hf-export-group-dropdown,
.hf-export-group-details {
    flex: 0 1 auto;
    margin-right: auto;
}
.hf-export-group-dropdown {
    position: relative;
    z-index: 20;
}
.hf-export-options-group-selector.card {
    margin: 0;
    min-width: 320px;
    max-width: 560px;
    flex: 1 1 420px;
    padding: 12px 16px;
}
.hf-export-options-group-selector-label {
    margin: 0 0 8px 0;
}
.hf-export-group-summary {
    width: 100%;
    min-width: 220px;
    position: relative;
    display: block;
    text-align: left;
    min-height: 0;
    line-height: 1.4;
    padding-left: 14px;
    padding-right: 40px;
    padding-top: 6px;
    padding-bottom: 6px;
}
[data-hf-export-group-summary-label] {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding-right: 28px;
    line-height: 1.4;
}
.hf-export-group-summary .dashicons {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    font-size: 20px;
    line-height: 20px;
    margin: 0;
    transition: transform 120ms ease-in-out;
}
.hf-export-group-dropdown.is-open .hf-export-group-summary .dashicons {
    transform: translateY(-50%) rotate(180deg);
}
.hf-export-group-panel {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: auto;
    width: 300px;
    min-width: 220px;
    max-width: 300px;
    max-height: 280px;
    overflow: auto;
    border: 1px solid #dcdcde;
    background: #fff;
    border-radius: 4px;
    padding: 8px 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    z-index: 1000;
}
.hf-export-group-panel[hidden] {
    display: none;
}
.hf-export-group-option {
    display: block;
    margin: 6px 0;
}
.hf-export-options-toolbar-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin-left: auto;
}
.hf-export-options-toolbar {
    width: 100%;
}
.hf-export-options-table tbody tr {
    cursor: pointer;
}
/* Import diff — diff2html overrides */
#hf-diff-container .d2h-file-collapse,
#hf-diff-container .d2h-moved-tag {
    display: none !important;
}
#hf-diff-container .d2h-file-wrapper {
    margin: 0 !important;
}
body .hyperpress-options-wrap.hf-diff-view {
    max-width: none;
}
CSS);
        }
    }

    /**
     * Process an uploaded file for the diff preview.
     *
     * The uploaded JSON is stored in a transient (5-minute TTL) so the confirmation
     * step can retrieve it without re-uploading.
     *
     * When $previewer is provided it replaces the built-in options-based logic.
     * The callable receives the JSON-decoded payload and must return:
     *   {success: bool, message?: string, transient_key?: string, current?: array, incoming?: array}
     *
     * @param array         $file                 The $_FILES['hf_import_file'] entry.
     * @param array         $allowedImportOptions Allowed option names.
     * @param string        $prefix               Prefix filter.
     * @param array         $options              Full options map (for snapshot scope).
     * @param callable|null $previewer            Optional custom previewer callable.
     * @return array{success: bool, message?: string, transient_key?: string, current?: array, incoming?: array}
     */
    private static function handlePreview(
        array $file,
        array $allowedImportOptions,
        string $prefix,
        array $options,
        ?callable $previewer = null,
    ): array {
        if (
            !isset($file['tmp_name'], $file['error'])
            || $file['error'] !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name'])
        ) {
            return ['success' => false, 'message' => __('No valid file was uploaded.', 'hyperfields')];
        }

        $maxBytes = 2 * 1024 * 1024; // 2 MB
        if (isset($file['size']) && (int) $file['size'] > $maxBytes) {
            return ['success' => false, 'message' => __('The uploaded file exceeds the 2 MB limit.', 'hyperfields')];
        }

        $jsonString = file_get_contents($file['tmp_name']); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
        if ($jsonString === false || $jsonString === '') {
            return ['success' => false, 'message' => __('Could not read the uploaded file.', 'hyperfields')];
        }

        $decoded = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => sprintf(__('Invalid JSON: %s', 'hyperfields'), json_last_error_msg())];
        }

        if (!is_array($decoded)) {
            return ['success' => false, 'message' => __('The uploaded file does not appear to be a valid export.', 'hyperfields')];
        }

        // Custom previewer: delegate validation, diffing, and snapshot entirely.
        if ($previewer !== null) {
            $result = call_user_func($previewer, $decoded, $jsonString, $allowedImportOptions, $prefix, $options);
            return is_array($result) ? $result : ['success' => false, 'message' => __('The custom previewer returned an invalid result.', 'hyperfields')];
        }

        // Default path: expect HyperFields options envelope.
        if (!isset($decoded['options']) || !is_array($decoded['options'])) {
            return ['success' => false, 'message' => __('The uploaded file does not appear to be a valid HyperFields export.', 'hyperfields')];
        }

        $filteredIncoming = [];
        foreach ($decoded['options'] as $optName => $value) {
            $optName = sanitize_text_field((string) $optName);
            if (!in_array($optName, $allowedImportOptions, true)) {
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            if ($prefix !== '') {
                $value = array_filter(
                    $value,
                    static fn($k): bool => strpos((string) $k, $prefix) === 0,
                    ARRAY_FILTER_USE_KEY
                );
            }
            $filteredIncoming[$optName] = $value;
        }

        if (empty($filteredIncoming)) {
            return ['success' => false, 'message' => __('No importable options were found in the uploaded file.', 'hyperfields')];
        }

        $currentSnapshot = ExportImport::snapshotOptions(array_keys($filteredIncoming), $prefix);

        $transientKey = 'hf_import_preview_' . md5(wp_generate_uuid4());
        set_transient($transientKey, $jsonString, 5 * MINUTE_IN_SECONDS);

        return [
            'success'       => true,
            'transient_key' => $transientKey,
            'current'       => $currentSnapshot,
            'incoming'      => $filteredIncoming,
        ];
    }

    /**
     * Output the full page HTML using HyperFields admin styles.
     */
    private static function renderHtml(
        string $title,
        string $description,
        array $options,
        array $optionGroups,
        string $prefix,
        string $exportJson,
        string $exportError,
        string $previewTransientKey,
        string $previewError,
        array $currentSnapshot,
        array $incomingData,
        string $importMessage,
        bool $importSuccess,
        ?string $exportFormExtras = null,
    ): void {
        $hasDiff = $previewTransientKey !== '' && !empty($incomingData);
        $cancelUrl  = admin_url('admin.php?page=' . esc_attr(sanitize_text_field(wp_unslash($_GET['page'] ?? ''))));
        $groupLabels = [];
        foreach ($options as $optKey => $_optLabel) {
            $groupLabel = (string) ($optionGroups[$optKey] ?? '');
            if ($groupLabel !== '') {
                $groupLabels[$groupLabel] = true;
            }
        }
        $groupLabels = array_keys($groupLabels);
        sort($groupLabels, SORT_NATURAL | SORT_FLAG_CASE);
        ?>
        <div class="wrap hyperpress hyperpress-options-wrap<?php echo $hasDiff ? ' hf-diff-view' : ''; ?>">
            <h1><?php echo esc_html($title); ?></h1>
            <p><?php echo esc_html($description); ?></p>

            <?php if ($importMessage): ?>
                <div class="notice notice-<?php echo $importSuccess ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($importMessage); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$hasDiff): ?>

            <?php if ($exportJson): ?>

            <!-- ====== EXPORT RESULT (JSON ONLY) ====== -->
            <h2><?php esc_html_e('Exported JSON', 'hyperfields'); ?></h2>
            <p><?php esc_html_e('Copy or download the exported JSON. Use "Back to selection" to run another export with different option groups.', 'hyperfields'); ?></p>

            <div style="display:flex;justify-content:flex-end;width:100%;margin-bottom:6px;">
                <button type="button"
                        id="hf-json-copy-btn"
                        class="button button-secondary"
                        style="display:inline-flex;align-items:center;gap:4px;"
                        aria-label="<?php echo esc_attr__('Copy JSON to clipboard', 'hyperfields'); ?>"
                        title="<?php echo esc_attr__('Copy JSON to clipboard', 'hyperfields'); ?>">
                    <span class="dashicons dashicons-admin-page" style="margin:0;" aria-hidden="true"></span>
                    <?php esc_html_e('Copy JSON', 'hyperfields'); ?>
                </button>
            </div>
            <div id="hf-json-viewer" style="border:1px solid #1f2937;border-radius:8px;overflow:auto;max-height:700px;background:#0f172a;padding:16px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono',monospace;font-size:13px;"></div>

            <!-- Raw JSON kept hidden so the download link and copy still work -->
            <textarea id="hf-json-raw" style="display:none;" aria-hidden="true"><?php echo esc_textarea($exportJson); ?></textarea>

            <p style="margin-top:12px;">
                <a href="data:application/json;charset=utf-8,<?php echo rawurlencode($exportJson); ?>"
                   download="hyperfields-export-<?php echo esc_attr(gmdate('Y-m-d')); ?>.json"
                   class="button button-primary">
                    <?php esc_html_e('Download JSON', 'hyperfields'); ?>
                </a>
                <a href="<?php echo esc_url($cancelUrl); ?>"
                   class="button button-secondary">
                    <?php esc_html_e('Back to Selection', 'hyperfields'); ?>
                </a>
            </p>

            <script>
            document.addEventListener('DOMContentLoaded', function () {
                var raw     = document.getElementById('hf-json-raw');
                var viewer  = document.getElementById('hf-json-viewer');
                var copyBtn = document.getElementById('hf-json-copy-btn');
                if (!raw || !viewer) { return; }

                if (copyBtn) {
                    var copyIcon = copyBtn.querySelector('.dashicons');
                    var copyLabel = '<?php echo esc_js(__('Copy JSON', 'hyperfields')); ?>';
                    var copiedLabel = '<?php echo esc_js(__('Copied!', 'hyperfields')); ?>';
                    var errorLabel = '<?php echo esc_js(__('Failed', 'hyperfields')); ?>';

                    function setCopyState(state) {
                        copyBtn.classList.remove('is-copied');
                        if (copyIcon) {
                            copyIcon.classList.remove('dashicons-admin-page', 'dashicons-yes-alt', 'dashicons-warning');
                        }
                        if (state === 'copied') {
                            copyBtn.classList.add('is-copied');
                            if (copyIcon) { copyIcon.classList.add('dashicons-yes-alt'); }
                            copyBtn.lastChild.textContent = ' ' + copiedLabel;
                        } else if (state === 'error') {
                            if (copyIcon) { copyIcon.classList.add('dashicons-warning'); }
                            copyBtn.lastChild.textContent = ' ' + errorLabel;
                        } else {
                            if (copyIcon) { copyIcon.classList.add('dashicons-admin-page'); }
                            copyBtn.lastChild.textContent = ' ' + copyLabel;
                        }
                    }

                    copyBtn.addEventListener('click', function () {
                        navigator.clipboard.writeText(raw.value).then(function () {
                            setCopyState('copied');
                            setTimeout(function () { setCopyState('idle'); }, 1500);
                        }).catch(function () {
                            setCopyState('error');
                            setTimeout(function () { setCopyState('idle'); }, 1800);
                        });
                    });
                }

                function initViewer() {
                    try {
                        var data = JSON.parse(raw.value);
                        viewer.innerHTML = '';
                        new JsonViewer({
                            value:           data,
                            theme:           'dark',
                            defaultInspectDepth: 2,
                            enableClipboard: false,
                        }).render(viewer);
                    } catch (e) {
                        viewer.innerHTML = '<pre style="color:#e5e7eb;margin:0;">' + raw.value.replace(/</g, '&lt;') + '</pre>';
                        console.error('hf-json-viewer error', e);
                    }
                }

                if (typeof JsonViewer !== 'undefined') {
                    initViewer();
                } else {
                    var s    = document.createElement('script');
                    s.src    = 'https://cdn.jsdelivr.net/npm/@textea/json-viewer@3';
                    s.onload = initViewer;
                    s.onerror = function () {
                        viewer.innerHTML = '<pre style="color:#e5e7eb;margin:0;">' + raw.value.replace(/</g, '&lt;') + '</pre>';
                    };
                    document.head.appendChild(s);
                }
            });
            </script>

            <?php else: ?>

            <!-- ====== EXPORT SECTION ====== -->
            <h2><?php esc_html_e('Export', 'hyperfields'); ?></h2>
            <p><?php esc_html_e('Select the option groups you want to include in the exported JSON file.', 'hyperfields'); ?></p>

            <?php if ($exportError): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($exportError); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('hf_export_action', 'hf_export_nonce'); ?>
                <fieldset class="hf-export-options">
                    <legend class="screen-reader-text"><?php esc_html_e('Option groups', 'hyperfields'); ?></legend>
                    <div class="hf-export-options-toolbar">
                        <div class="hf-export-options-toolbar-row">
                            <?php if (!empty($groupLabels)): ?>

                                <div data-hf-export-group-selector class="hf-export-group-dropdown">
                                    <button type="button"
                                            data-hf-export-group-summary
                                            class="button button-secondary hf-export-group-summary"
                                            aria-expanded="false"
                                            aria-controls="hf-export-group-panel">
                                        <span data-hf-export-group-summary-label><?php esc_html_e('Select option groups', 'hyperfields'); ?></span>
                                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                    </button>
                                    <div id="hf-export-group-panel" data-hf-export-group-panel class="hf-export-group-panel" hidden>
                                        <?php foreach ($groupLabels as $groupLabel): ?>
                                            <label class="hf-export-group-option">
                                                <input type="checkbox"
                                                       data-hf-export-group-toggle
                                                       value="<?php echo esc_attr($groupLabel); ?>">
                                                <span><?php echo esc_html($groupLabel); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                            <?php endif; ?>
                            <div class="hf-export-options-toolbar-actions">
                            <button type="button" class="button button-secondary" data-hf-export-toggle="all">
                                <?php esc_html_e('Check all', 'hyperfields'); ?>
                            </button>
                            <button type="button" class="button button-secondary" data-hf-export-toggle="none">
                                <?php esc_html_e('Uncheck all', 'hyperfields'); ?>
                            </button>
                            <button type="button" class="button button-secondary" data-hf-export-toggle="invert">
                                <?php esc_html_e('Invert selection', 'hyperfields'); ?>
                            </button>
                            </div>
                        </div>
                    </div>
                    <div class="hf-export-options-filter">
                        <div class="hf-export-options-filter-header">
                            <label for="hf_export_options_filter">
                                <?php esc_html_e('Filter options', 'hyperfields'); ?>
                            </label>
                            <span class="description" data-hf-export-filter-count></span>
                        </div>
                        <div class="hf-export-options-filter-controls" style="margin-bottom: 1rem;">
                            <input type="search"
                                   id="hf_export_options_filter"
                                   class="regular-text"
                                   data-hf-export-filter
                                   placeholder="<?php echo esc_attr__('Type to filter by option group or key', 'hyperfields'); ?>">
                            <button type="button" class="button button-secondary" data-hf-export-filter-clear>
                                <?php esc_html_e('Clear', 'hyperfields'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="hf-export-options-table-wrap">
                        <table class="widefat striped fixed hf-export-options-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Group', 'hyperfields'); ?></th>
                                    <th scope="col"><?php esc_html_e('Option Group', 'hyperfields'); ?></th>
                                    <th scope="col"><?php esc_html_e('Option Key', 'hyperfields'); ?></th>
                                    <th scope="col" class="hf-export-option-select-column">
                                        <?php esc_html_e('Include', 'hyperfields'); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($options as $optKey => $optLabel): ?>
                                <?php $groupLabel = (string) ($optionGroups[$optKey] ?? 'Other'); ?>
                                <tr data-hf-export-group="<?php echo esc_attr($groupLabel); ?>">
                                    <td>
                                        <span><?php echo esc_html($groupLabel); ?></span>
                                    </td>
                                    <th scope="row">
                                        <label for="hf_opt_<?php echo esc_attr($optKey); ?>">
                                            <?php echo esc_html($optLabel); ?>
                                        </label>
                                    </th>
                                    <td>
                                        <code><?php echo esc_html($optKey); ?></code>
                                    </td>
                                    <td class="hf-export-option-checkbox-cell">
                                        <input type="checkbox"
                                               id="hf_opt_<?php echo esc_attr($optKey); ?>"
                                               name="hf_export_options[]"
                                               value="<?php echo esc_attr($optKey); ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </fieldset>

                <?php if ($exportFormExtras !== null): ?>
                    <?php echo $exportFormExtras; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller controls HTML. ?>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" name="hf_export_submit" class="button button-primary">
                        <?php esc_html_e('Export to JSON', 'hyperfields'); ?>
                    </button>
                    <span class="spinner"></span>
                </p>
            </form>

            <hr>

            <!-- ====== IMPORT SECTION ====== -->
            <h2><?php esc_html_e('View Diff. / Import', 'hyperfields'); ?></h2>
            <p><?php esc_html_e('Upload a previously exported JSON file. You will be shown a preview of what will change before confirming.', 'hyperfields'); ?></p>

            <?php if ($previewError): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($previewError); ?></p></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('hf_preview_action', 'hf_preview_nonce'); ?>
                <div class="hyperpress-fields-group">
                    <div class="hyperpress-field-wrapper">
                        <div class="hyperpress-field-row">
                            <div class="hyperpress-field-label">
                                <label for="hf_import_file">
                                    <?php esc_html_e('JSON file', 'hyperfields'); ?>
                                </label>
                            </div>
                            <div class="hyperpress-field-input-wrapper">
                                <input type="file" id="hf_import_file" name="hf_import_file"
                                       accept=".json,application/json" required>
                                <p class="description">
                                    <?php esc_html_e('Maximum file size: 2 MB.', 'hyperfields'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="submit">
                    <button type="submit" name="hf_preview_submit" class="button button-secondary">
                        <?php esc_html_e('Preview Changes (view Diff.)', 'hyperfields'); ?>
                    </button>
                </p>
            </form>

            <?php endif; ?>

            <?php else: // Import diff preview ?>

            <!-- ====== DIFF PREVIEW SECTION ====== -->
            <h2><?php esc_html_e('Import Preview', 'hyperfields'); ?></h2>
            <p><?php esc_html_e('Review the changes below. Keys highlighted in green will be added or updated; keys in red will be removed.', 'hyperfields'); ?></p>
            <p><em><?php esc_html_e('Current settings are shown on the left; imported values are on the right.', 'hyperfields'); ?></em></p>

            <div id="hf-diff-container" style="overflow:auto;max-height:900px;border:1px solid #1f2937;border-radius:8px;">
                <p style="padding:16px;"><?php esc_html_e('Loading diff…', 'hyperfields'); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('hf_confirm_action', 'hf_confirm_nonce'); ?>
                <input type="hidden" name="hf_transient_key" value="<?php echo esc_attr($previewTransientKey); ?>">
                <div style="margin-top:15px;padding:12px;border-left:4px solid #d63638;background:#fff5f5;">
                    <p style="margin:0 0 8px 0;color:#d63638;">
                        <strong><?php esc_html_e('⚠️ Warning: This action is destructive.', 'hyperfields'); ?></strong>
                    </p>
                    <p style="margin:0 0 12px 0;" class="description">
                        <?php esc_html_e('Performing an import will overwrite existing settings with the values from the uploaded file. This cannot be undone.', 'hyperfields'); ?>
                    </p>
                    <label style="display:block;margin-top:8px;cursor:pointer;">
                        <input type="checkbox"
                               id="hf_import_confirm_destructive"
                               onchange="document.getElementById('hf_confirm_submit_btn').disabled=!this.checked;">
                        <span style="font-weight:600;">
                            <?php esc_html_e('I understand performing an import will overwrite existing settings and cannot be undone.', 'hyperfields'); ?>
                        </span>
                    </label>
                </div>
                <p class="submit">
                    <button type="submit"
                            id="hf_confirm_submit_btn"
                            name="hf_confirm_submit"
                            class="button button-primary"
                            disabled>
                        <?php esc_html_e('Confirm Import', 'hyperfields'); ?>
                    </button>
                    <a href="<?php echo esc_url($cancelUrl); ?>"
                       class="button button-secondary">
                        <?php esc_html_e('Cancel', 'hyperfields'); ?>
                    </a>
                </p>
            </form>

            <script>
            (function () {
                var current   = <?php echo wp_json_encode($currentSnapshot, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                var incoming  = <?php echo wp_json_encode($incomingData, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                var container = document.getElementById('hf-diff-container');
                if (!container) { return; }

                var noChange = '<p style="padding:16px;"><strong><?php echo esc_js(__('No differences found. The uploaded file matches the current settings.', 'hyperfields')); ?></strong></p>';
                var errMsg   = '<p style="padding:16px;"><?php echo esc_js(__('Could not load or render diff. Please check the browser console for details.', 'hyperfields')); ?></p>';

                function loadScript(src, id, cb) {
                    if (document.getElementById(id)) { cb(); return; }
                    var s  = document.createElement('script');
                    s.id   = id;
                    s.src  = src;
                    s.onload  = cb;
                    s.onerror = function () { container.innerHTML = errMsg; console.error('hf-diff: failed to load ' + src); };
                    document.head.appendChild(s);
                }

                function loadCss(href, id) {
                    if (document.getElementById(id)) { return; }
                    var l  = document.createElement('link');
                    l.id   = id;
                    l.rel  = 'stylesheet';
                    l.href = href;
                    document.head.appendChild(l);
                }

                function render() {
                    try {
                        var leftStr  = JSON.stringify(current,  null, 2);
                        var rightStr = JSON.stringify(incoming, null, 2);

                        var unifiedDiff = Diff.createTwoFilesPatch(
                            'current', 'incoming',
                            leftStr, rightStr,
                            '', '',
                            { context: 4 }
                        );

                        if (unifiedDiff.split('\n').slice(2).every(function (l) { return l[0] !== '+' && l[0] !== '-'; })) {
                            container.innerHTML = noChange;
                            return;
                        }

                        loadCss('https://cdn.jsdelivr.net/npm/diff2html@3.4.56/bundles/css/diff2html.min.css', 'hf-diff2html-css');
                        loadCss('https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css', 'hf-hljs-css');

                        var ui = new Diff2HtmlUI(container, unifiedDiff, {
                            drawFileList:       false,
                            matching:           'lines',
                            outputFormat:       'side-by-side',
                            diffStyle:          'char',
                            colorScheme:        'dark',
                            synchronisedScroll: true,
                            highlight:          true,
                        });
                        ui.draw();
                        ui.highlightCode();
                        ui.synchronisedScroll();
                    } catch (e) {
                        container.innerHTML = errMsg;
                        console.error('hf-diff error', e);
                    }
                }

                loadScript(
                    'https://cdn.jsdelivr.net/npm/diff@7/dist/diff.min.js',
                    'hf-diff-js',
                    function () {
                        loadScript(
                            'https://cdn.jsdelivr.net/npm/diff2html@3.4.56/bundles/js/diff2html-ui.min.js',
                            'hf-diff2html-ui-js',
                            render
                        );
                    }
                );
            })();
            </script>

            <?php endif; ?>

        </div><!-- /.wrap -->
        <?php
    }
}
