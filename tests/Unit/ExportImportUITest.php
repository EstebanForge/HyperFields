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
        Functions\when('remove_query_arg')->justReturn('http://example.com/admin');
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
}
