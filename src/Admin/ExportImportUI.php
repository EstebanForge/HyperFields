<?php

declare(strict_types=1);

namespace HyperFields\Admin;

use HyperFields\ExportImport;

/**
 * Reusable Export/Import UI component.
 *
 * Generates an HTML page fragment (suitable for a WP admin submenu callback)
 * that lets users:
 *  1. Export selected option groups to a downloadable JSON file.
 *  2. Upload a JSON file and preview a visual diff of what will change.
 *  3. Confirm the import after reviewing the diff.
 *
 * No WordPress menu hooks are registered here; the calling plugin/theme is
 * responsible for adding the submenu page and echoing the output of render().
 *
 * Usage example (in a plugin that uses HyperFields):
 * ```php
 * add_action('admin_menu', function () {
 *     add_submenu_page(
 *         'my-plugin',
 *         'Data Tools',
 *         'Data Tools',
 *         'manage_options',
 *         'my-plugin-data-tools',
 *         function () {
 *             echo \HyperFields\Admin\ExportImportUI::render(
 *                 options: [
 *                     'my_plugin_options' => 'My Plugin Settings',
 *                     'wpseo'             => 'Yoast SEO (read-only export)',
 *                 ],
 *                 allowedImportOptions: ['my_plugin_options'],
 *                 prefix: 'myp_',
 *                 title: 'My Plugin – Data Tools',
 *             );
 *         }
 *     );
 * });
 * ```
 */
class ExportImportUI
{
    /**
     * Render the complete export/import UI as an HTML string.
     *
     * @param array  $options              Associative map of WP option names to human-readable labels.
     *                                     These are the options that will appear in the export dropdown
     *                                     and the import whitelist.
     *                                     Example: ['myplugin_options' => 'My Plugin Settings']
     * @param array  $allowedImportOptions Whitelist of option names that are permitted to be overwritten
     *                                     on import.  Defaults to all keys in $options.
     * @param string $prefix               Optional prefix filter applied to both export and import
     *                                     (only keys starting with this prefix are processed).
     * @param string $title                Page heading displayed at the top.
     * @param string $description          Short description shown below the heading.
     * @return string HTML ready to be echo'd inside a WP admin page callback.
     */
    public static function render(
        array $options = [],
        array $allowedImportOptions = [],
        string $prefix = '',
        string $title = 'Data Export / Import',
        string $description = 'Export your settings to JSON or import a previously exported file.'
    ): string {
        // Default allowed import options to all available option keys
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

            // Only export names that are in the allowed list
            $selectedNames = array_values(array_intersect($selectedNames, array_keys($options)));

            if (empty($selectedNames)) {
                $exportError = 'Please select at least one option group to export.';
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
            $file = $_FILES['hf_import_file'];
            $previewResult = self::handlePreview($file, $allowedImportOptions, $prefix, $options);

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
                $result = ExportImport::importOptions($storedJson, $allowedImportOptions, $prefix);
                $importSuccess = $result['success'];
                $importMessage = $result['message'];
                delete_transient($transientKey);
            } else {
                $importMessage = 'Import session expired or is invalid. Please upload the file again.';
            }
        }

        // ---------- Render ----------
        ob_start();
        self::renderHtml(
            title: $title,
            description: $description,
            options: $options,
            prefix: $prefix,
            exportJson: $exportJson,
            exportError: $exportError,
            previewTransientKey: $previewTransientKey,
            previewError: $previewError,
            currentSnapshot: $currentSnapshot,
            incomingData: $incomingData,
            importMessage: $importMessage,
            importSuccess: $importSuccess,
        );

        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Process an uploaded file for the diff preview.
     *
     * The uploaded JSON is stored in a transient (5-minute TTL) so the second
     * POST (confirmation) can retrieve it without re-uploading.  The actual
     * current DB values are captured as a snapshot so they can be diffed in the
     * browser; the snapshot itself is never sent to the client – only the diff
     * output is rendered.
     *
     * @param array  $file                 The $_FILES['hf_import_file'] entry.
     * @param array  $allowedImportOptions Allowed option names.
     * @param string $prefix               Prefix filter.
     * @param array  $options              Full options map (for snapshot scope).
     * @return array{success: bool, message?: string, transient_key?: string, current?: array, incoming?: array}
     */
    private static function handlePreview(
        array $file,
        array $allowedImportOptions,
        string $prefix,
        array $options
    ): array {
        // Basic file validation
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'No valid file was uploaded.'];
        }

        $maxBytes = 2 * 1024 * 1024; // 2 MB
        if (isset($file['size']) && (int) $file['size'] > $maxBytes) {
            return ['success' => false, 'message' => 'The uploaded file exceeds the 2 MB limit.'];
        }

        $jsonString = file_get_contents($file['tmp_name']); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
        if ($jsonString === false || $jsonString === '') {
            return ['success' => false, 'message' => 'Could not read the uploaded file.'];
        }

        $decoded = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        if (!is_array($decoded) || !isset($decoded['options']) || !is_array($decoded['options'])) {
            return ['success' => false, 'message' => 'The uploaded file does not appear to be a valid HyperFields export.'];
        }

        // Filter to only options that are both in the payload and allowed
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
                    static fn(string $k): bool => strpos($k, $prefix) === 0,
                    ARRAY_FILTER_USE_KEY
                );
            }
            $filteredIncoming[$optName] = $value;
        }

        if (empty($filteredIncoming)) {
            return ['success' => false, 'message' => 'No importable options were found in the uploaded file.'];
        }

        // Capture current DB snapshot for the diff
        $snapshotNames   = array_keys($filteredIncoming);
        $currentSnapshot = ExportImport::snapshotOptions($snapshotNames, $prefix);

        // Store the raw JSON in a transient for the confirmation step
        $transientKey = 'hf_import_preview_' . md5(wp_generate_uuid4());
        set_transient($transientKey, $jsonString, 5 * MINUTE_IN_SECONDS);

        return [
            'success'      => true,
            'transient_key' => $transientKey,
            'current'      => $currentSnapshot,
            'incoming'     => $filteredIncoming,
        ];
    }

    /**
     * Output the HTML for the full UI.
     *
     * All parameters are already sanitized/escaped before being passed here.
     */
    private static function renderHtml(
        string $title,
        string $description,
        array $options,
        string $prefix,
        string $exportJson,
        string $exportError,
        string $previewTransientKey,
        string $previewError,
        array $currentSnapshot,
        array $incomingData,
        string $importMessage,
        bool $importSuccess
    ): void {
        $hasDiff = $previewTransientKey !== '' && !empty($incomingData);
        ?>
        <div class="wrap hf-export-import-wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <p><?php echo esc_html($description); ?></p>

            <?php if ($importMessage): ?>
                <div class="notice notice-<?php echo $importSuccess ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($importMessage); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$hasDiff): // Hide export/upload sections while previewing a diff ?>

            <!-- ====== EXPORT SECTION ====== -->
            <div class="hf-section">
                <h2><?php esc_html_e('Export', 'hyperfields'); ?></h2>
                <p><?php esc_html_e('Select the option groups you want to include in the exported JSON file.', 'hyperfields'); ?></p>

                <?php if ($exportError): ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html($exportError); ?></p></div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field('hf_export_action', 'hf_export_nonce'); ?>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e('Option groups', 'hyperfields'); ?></legend>
                        <?php foreach ($options as $optKey => $optLabel): ?>
                            <label>
                                <input type="checkbox" name="hf_export_options[]"
                                       value="<?php echo esc_attr($optKey); ?>" checked>
                                <?php echo esc_html($optLabel); ?>
                                <code style="margin-left:4px;opacity:.7;">(<?php echo esc_html($optKey); ?>)</code>
                            </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                    <p>
                        <button type="submit" name="hf_export_submit" class="button button-primary">
                            <?php esc_html_e('Export to JSON', 'hyperfields'); ?>
                        </button>
                    </p>
                </form>

                <?php if ($exportJson): ?>
                    <h3><?php esc_html_e('Exported JSON', 'hyperfields'); ?></h3>
                    <textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea($exportJson); ?></textarea>
                    <p>
                        <a href="data:application/json;charset=utf-8,<?php echo rawurlencode($exportJson); ?>"
                           download="hyperfields-export-<?php echo esc_attr(gmdate('Y-m-d')); ?>.json"
                           class="button">
                            <?php esc_html_e('⬇ Download JSON', 'hyperfields'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div><!-- /.hf-section (export) -->

            <hr>

            <!-- ====== IMPORT / PREVIEW SECTION ====== -->
            <div class="hf-section">
                <h2><?php esc_html_e('Import', 'hyperfields'); ?></h2>
                <p><?php esc_html_e('Upload a previously exported JSON file. You will be shown a preview of what will change before confirming.', 'hyperfields'); ?></p>

                <?php if ($previewError): ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html($previewError); ?></p></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('hf_preview_action', 'hf_preview_nonce'); ?>
                    <p>
                        <input type="file" name="hf_import_file" accept=".json,application/json" required>
                    </p>
                    <p>
                        <button type="submit" name="hf_preview_submit" class="button button-secondary">
                            <?php esc_html_e('Preview Changes', 'hyperfields'); ?>
                        </button>
                    </p>
                </form>
            </div><!-- /.hf-section (import) -->

            <?php else: // Diff preview ?>

            <!-- ====== DIFF PREVIEW SECTION ====== -->
            <div class="hf-section hf-diff-section">
                <h2><?php esc_html_e('Import Preview', 'hyperfields'); ?></h2>
                <p><?php esc_html_e('Review the changes below. Fields highlighted in green will be added or updated; fields in red will be removed.', 'hyperfields'); ?></p>
                <p><em><?php esc_html_e('The current settings are shown on the left; the imported values are shown on the right.', 'hyperfields'); ?></em></p>

                <div id="hf-diff-container" style="background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:4px;overflow:auto;max-height:600px;">
                    <p><?php esc_html_e('Loading diff…', 'hyperfields'); ?></p>
                </div>

                <!-- Confirm / Cancel -->
                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field('hf_confirm_action', 'hf_confirm_nonce'); ?>
                    <input type="hidden" name="hf_transient_key" value="<?php echo esc_attr($previewTransientKey); ?>">
                    <button type="submit" name="hf_confirm_submit" class="button button-primary">
                        <?php esc_html_e('Confirm Import', 'hyperfields'); ?>
                    </button>
                    <a href="<?php echo esc_url(remove_query_arg(['hf_preview'])); ?>"
                       class="button button-secondary" style="margin-left:8px;">
                        <?php esc_html_e('Cancel', 'hyperfields'); ?>
                    </a>
                </form>
            </div><!-- /.hf-section (diff) -->

            <!-- jsondiffpatch diff renderer -->
            <link rel="stylesheet"
                  href="https://cdn.jsdelivr.net/npm/jsondiffpatch@0.6.0/public/formatters-styles/html.css">
            <script src="https://cdn.jsdelivr.net/npm/jsondiffpatch@0.6.0/dist/jsondiffpatch.umd.js"></script>
            <script>
            (function () {
                var current  = <?php echo wp_json_encode($currentSnapshot); ?>;
                var incoming = <?php echo wp_json_encode($incomingData); ?>;
                var container = document.getElementById('hf-diff-container');

                if (!window.jsondiffpatch || !container) { return; }

                try {
                    var delta = jsondiffpatch.diff(current, incoming);
                    if (!delta) {
                        container.innerHTML = '<p><strong><?php echo esc_js(__('No differences found. The uploaded file matches the current settings.', 'hyperfields')); ?></strong></p>';
                        return;
                    }
                    container.innerHTML = '';
                    var diffHtml = jsondiffpatch.formatters.html.format(delta, current);
                    container.innerHTML = diffHtml;
                    jsondiffpatch.formatters.html.hideUnchanged();
                } catch (e) {
                    container.innerHTML = '<p><?php echo esc_js(__('Could not render diff. Please check the browser console for details.', 'hyperfields')); ?></p>';
                    console.error('jsondiffpatch error', e);
                }
            })();
            </script>

            <?php endif; // end diff/normal toggle ?>

        </div><!-- /.wrap -->
        <?php
    }
}
