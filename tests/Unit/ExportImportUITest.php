<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Admin\ExportImportUI;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ExportImportUITest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('wp_nonce_field')->alias(function (string $action, string $name) {
            echo '<input type="hidden" name="' . $name . '" value="test_nonce">';
        });
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('wp_json_encode')->alias(function ($data, $flags = 0) {
            return json_encode($data, $flags);
        });
        Functions\when('wp_generate_uuid4')->justReturn('test-uuid-1234-5678-abcd');
        Functions\when('esc_js')->returnArg();
    }

    protected function tearDown(): void
    {
        $_POST  = [];
        $_FILES = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Basic rendering
    // -------------------------------------------------------------------------

    public function testRenderReturnsString(): void
    {
        $html = ExportImportUI::render(
            options: ['my_option' => 'My Options'],
        );

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
    }

    public function testRenderContainsTitle(): void
    {
        $html = ExportImportUI::render(
            options: ['my_option' => 'My Options'],
            title: 'Custom Page Title',
        );

        $this->assertStringContainsString('Custom Page Title', $html);
    }

    public function testRenderContainsDescription(): void
    {
        $html = ExportImportUI::render(
            options: [],
            description: 'My custom description text.',
        );

        $this->assertStringContainsString('My custom description text.', $html);
    }

    public function testRenderContainsOptionLabels(): void
    {
        $html = ExportImportUI::render(
            options: [
                'option_a' => 'Option A Label',
                'option_b' => 'Option B Label',
            ],
        );

        $this->assertStringContainsString('Option A Label', $html);
        $this->assertStringContainsString('Option B Label', $html);
    }

    public function testRenderContainsExportAndImportForms(): void
    {
        $html = ExportImportUI::render(options: ['my_opt' => 'My Opt']);

        $this->assertStringContainsString('hf_export_submit', $html);
        $this->assertStringContainsString('hf_preview_submit', $html);
    }

    public function testRenderDefaultTitle(): void
    {
        $html = ExportImportUI::render(options: []);

        $this->assertStringContainsString('Data Export / Import', $html);
    }

    // -------------------------------------------------------------------------
    // Export handling
    // -------------------------------------------------------------------------

    public function testExportHandlingRendersJsonTextarea(): void
    {
        $_POST = [
            'hf_export_submit'  => '1',
            'hf_export_nonce'   => 'test_nonce',
            'hf_export_options' => ['my_option'],
        ];

        Functions\when('get_option')->justReturn(['field_a' => 'value_a']);
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');
        Functions\when('get_site_url')->justReturn('https://example.com');

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('value_a', $html);
    }

    public function testExportHandlingBlocksUnallowedOptions(): void
    {
        $_POST = [
            'hf_export_submit'  => '1',
            'hf_export_nonce'   => 'test_nonce',
            'hf_export_options' => ['not_in_whitelist'],
        ];

        Functions\when('get_option')->justReturn(['key' => 'val']);
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');
        Functions\when('get_site_url')->justReturn('https://example.com');

        $html = ExportImportUI::render(options: ['allowed_option' => 'Allowed']);

        // Error or no data for disallowed option
        $this->assertStringContainsString('Please select at least one', $html);
    }

    // -------------------------------------------------------------------------
    // Prefix
    // -------------------------------------------------------------------------

    public function testPrefixIsPassedToExport(): void
    {
        $_POST = [
            'hf_export_submit'  => '1',
            'hf_export_nonce'   => 'test_nonce',
            'hf_export_options' => ['my_option'],
        ];

        Functions\when('get_option')->justReturn([
            'myp_key'   => 'val1',
            'other_key' => 'val2',
        ]);
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');
        Functions\when('get_site_url')->justReturn('https://example.com');

        $html = ExportImportUI::render(
            options: ['my_option' => 'My Options'],
            prefix: 'myp_'
        );

        // The exported JSON (in the textarea) should only contain myp_ keys
        $this->assertStringContainsString('myp_key', $html);
        $this->assertStringNotContainsString('other_key', $html);
    }

    // -------------------------------------------------------------------------
    // Import confirmation
    // -------------------------------------------------------------------------

    public function testConfirmImportDisplaysSuccessNotice(): void
    {
        $_POST = [
            'hf_confirm_submit' => '1',
            'hf_confirm_nonce'  => 'test_nonce',
            'hf_transient_key'  => 'hf_import_preview_abc',
        ];

        $exportedJson = json_encode([
            'version'     => '1.0',
            'type'        => 'hyperfields_export',
            'prefix'      => '',
            'exported_at' => '2024-01-01 00:00:00',
            'site_url'    => 'https://example.com',
            'options'     => ['my_option' => ['key' => 'val']],
        ]);

        Functions\when('get_transient')->justReturn($exportedJson);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('set_transient')->justReturn(true);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-success', $html);
    }

    public function testConfirmImportExpiredTransientDisplaysError(): void
    {
        $_POST = [
            'hf_confirm_submit' => '1',
            'hf_confirm_nonce'  => 'test_nonce',
            'hf_transient_key'  => 'expired_key',
        ];

        Functions\when('get_transient')->justReturn(false);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('expired', $html);
    }

    public function testConfirmImportWithNoTransientKeyFallsBackToExpired(): void
    {
        // hf_transient_key not in POST → storedJson = false
        $_POST = [
            'hf_confirm_submit' => '1',
            'hf_confirm_nonce'  => 'test_nonce',
        ];

        Functions\when('get_transient')->justReturn(false);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-error', $html);
    }

    public function testExportHandlingWithNoOptionsSelectedReturnsEmptyArray(): void
    {
        // hf_export_options key absent → $selectedNames = []
        $_POST = [
            'hf_export_submit' => '1',
            'hf_export_nonce'  => 'test_nonce',
            // intentionally no hf_export_options key
        ];

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('Please select at least one', $html);
    }

    // -------------------------------------------------------------------------
    // Preview upload via $_FILES
    // -------------------------------------------------------------------------

    /**
     * Build a minimal $_FILES entry backed by a real temp file so is_uploaded_file
     * cannot be called (we override it).  handlePreview is private so we drive it
     * through render().
     */
    private function makeUploadedFile(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hf_test_');
        file_put_contents($tmp, $content);

        return [
            'tmp_name' => $tmp,
            'name'     => 'test.json',
            'type'     => 'application/json',
            'size'     => strlen($content),
            'error'    => UPLOAD_ERR_OK,
        ];
    }

    public function testPreviewWithInvalidFileErrorReturnsError(): void
    {
        $_POST = [
            'hf_preview_submit' => '1',
            'hf_preview_nonce'  => 'test_nonce',
        ];
        $_FILES = [
            'hf_import_file' => [
                'tmp_name' => '',
                'name'     => 'test.json',
                'type'     => 'application/json',
                'size'     => 0,
                'error'    => UPLOAD_ERR_NO_FILE,
            ],
        ];

        Functions\when('is_uploaded_file')->justReturn(false);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('No valid file', $html);
    }

    public function testPreviewWithOversizedFileReturnsError(): void
    {
        $file = $this->makeUploadedFile('{}');
        $file['size'] = 3 * 1024 * 1024; // 3 MB — over limit

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('2 MB', $html);

        unlink($file['tmp_name']);
    }

    public function testPreviewWithInvalidJsonReturnsError(): void
    {
        $file = $this->makeUploadedFile('{not valid json}');

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('Invalid JSON', $html);

        unlink($file['tmp_name']);
    }

    public function testPreviewWithMissingOptionsKeyReturnsError(): void
    {
        $json = json_encode(['version' => '1.0', 'type' => 'hyperfields_export']);
        $file = $this->makeUploadedFile((string) $json);

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('valid HyperFields export', $html);

        unlink($file['tmp_name']);
    }

    public function testPreviewWithNoAllowedOptionsReturnsError(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'type'    => 'hyperfields_export',
            'options' => ['blocked_option' => ['k' => 'v']],
        ]);
        $file = $this->makeUploadedFile((string) $json);

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);

        $html = ExportImportUI::render(
            options: ['allowed_option' => 'Allowed'],
            allowedImportOptions: ['allowed_option'],
        );

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('No importable options', $html);

        unlink($file['tmp_name']);
    }

    public function testPreviewSuccessShowsDiffSection(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'type'    => 'hyperfields_export',
            'options' => ['my_option' => ['field_a' => 'value_a']],
        ]);
        $file = $this->makeUploadedFile((string) $json);

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);
        Functions\when('get_option')->justReturn(['existing_key' => 'existing_val']);
        Functions\when('set_transient')->justReturn(true);

        $html = ExportImportUI::render(
            options: ['my_option' => 'My Options'],
            allowedImportOptions: ['my_option'],
        );

        $this->assertStringContainsString('hf_confirm_submit', $html);
        $this->assertStringContainsString('hf-diff-container', $html);
        $this->assertStringContainsString('hyperpress-options-wrap', $html);

        unlink($file['tmp_name']);
    }

    public function testPreviewWithEmptyFileReturnsError(): void
    {
        // An empty file causes the "Could not read" branch (empty string check).
        $file = $this->makeUploadedFile(''); // zero-byte file

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);

        $html = ExportImportUI::render(options: ['my_option' => 'My Options']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('Could not read', $html);

        unlink($file['tmp_name']);
    }

    public function testPreviewSkipsNonArrayOptionValuesInPayload(): void
    {
        // Payload has a non-array value for one option → it's skipped silently,
        // but a valid option in the same payload still produces a successful preview.
        $json = json_encode([
            'version' => '1.0',
            'type'    => 'hyperfields_export',
            'options' => [
                'my_option'  => ['field_a' => 'val'],
                'bad_option' => 'scalar',
            ],
        ]);
        $file = $this->makeUploadedFile((string) $json);

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('set_transient')->justReturn(true);

        $html = ExportImportUI::render(
            options: ['my_option' => 'My Options', 'bad_option' => 'Bad'],
            allowedImportOptions: ['my_option', 'bad_option'],
        );

        $this->assertStringContainsString('hf-diff-container', $html);
        $this->assertStringContainsString('hf_confirm_submit', $html);

        unlink($file['tmp_name']);
    }

    public function testPreviewAppliesPrefixFilterToIncomingValues(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'type'    => 'hyperfields_export',
            'options' => [
                'my_option' => [
                    'myp_field' => 'kept',
                    'other_key' => 'dropped',
                ],
            ],
        ]);
        $file = $this->makeUploadedFile((string) $json);

        $_POST  = ['hf_preview_submit' => '1', 'hf_preview_nonce' => 'test_nonce'];
        $_FILES = ['hf_import_file' => $file];

        Functions\when('is_uploaded_file')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('set_transient')->justReturn(true);

        $html = ExportImportUI::render(
            options: ['my_option' => 'My Options'],
            allowedImportOptions: ['my_option'],
            prefix: 'myp_',
        );

        $this->assertStringContainsString('hf-diff-container', $html);
        $this->assertStringContainsString('myp_field', $html);
        $this->assertStringNotContainsString('other_key', $html);

        unlink($file['tmp_name']);
    }

    public function testEnqueuePageAssetsCallsEnqueueFunctions(): void
    {
        // enqueuePageAssets() is the correct hook target — verify it calls wp_enqueue_*.
        // TemplateLoader::enqueueAssets() bails when HYPERFIELDS_PLUGIN_URL is undefined,
        // so only the jsondiffpatch calls are observable here.
        $styleEnqueued  = false;
        $scriptEnqueued = false;

        Functions\when('wp_enqueue_style')->alias(function () use (&$styleEnqueued) {
            $styleEnqueued = true;
        });
        Functions\when('wp_enqueue_script')->alias(function () use (&$scriptEnqueued) {
            $scriptEnqueued = true;
        });

        ExportImportUI::enqueuePageAssets();

        $this->assertTrue($styleEnqueued, 'jsondiffpatch CSS should be enqueued');
        $this->assertTrue($scriptEnqueued, 'jsondiffpatch JS should be enqueued');
    }
}
