<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\ExportImport;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ExportImportTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubTranslationFunctions();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_json_encode')->alias(function ($data, $flags = 0) {
            return json_encode($data, $flags);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // exportOptions
    // -------------------------------------------------------------------------

    public function testExportOptionsBasic(): void
    {
        Functions\when('get_option')->justReturn(['key1' => 'val1', 'key2' => 'val2']);

        $json = ExportImport::exportOptions(['my_option']);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertSame('hyperfields_export', $data['type']);
        $this->assertArrayHasKey('options', $data);
        $this->assertArrayHasKey('my_option', $data['options']);
        // Typed-node envelope format
        $this->assertArrayHasKey('value', $data['options']['my_option']);
        $this->assertArrayHasKey('_schema', $data['options']['my_option']);
        $this->assertSame('val1', $data['options']['my_option']['value']['key1']);
    }

    public function testExportOptionsWithPrefixFilter(): void
    {
        Functions\when('get_option')->justReturn([
            'myplugin_name'  => 'John',
            'myplugin_email' => 'john@example.com',
            'other_setting'  => 'should_be_excluded',
        ]);

        $json = ExportImport::exportOptions(['my_option'], 'myplugin_');
        $data = json_decode($json, true);

        $exported = $data['options']['my_option']['value'];
        $this->assertArrayHasKey('myplugin_name', $exported);
        $this->assertArrayHasKey('myplugin_email', $exported);
        $this->assertArrayNotHasKey('other_setting', $exported);
        $this->assertSame('myplugin_', $data['prefix']);
    }

    public function testExportOptionsMultipleOptionNames(): void
    {
        Functions\expect('get_option')
            ->with('option_a', [])
            ->andReturn(['a_key' => 'a_val'])
            ->once();

        Functions\expect('get_option')
            ->with('option_b', [])
            ->andReturn(['b_key' => 'b_val'])
            ->once();

        $json = ExportImport::exportOptions(['option_a', 'option_b']);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('option_a', $data['options']);
        $this->assertArrayHasKey('option_b', $data['options']);
    }

    public function testExportOptionsSupportsScalarValues(): void
    {
        Functions\when('get_option')->justReturn('scalar_value');

        $json = ExportImport::exportOptions(['my_scalar_option']);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('my_scalar_option', $data['options']);
        // Typed-node envelope for scalar
        $this->assertArrayHasKey('value', $data['options']['my_scalar_option']);
        $this->assertSame('scalar_value', $data['options']['my_scalar_option']['value']);
        $this->assertSame('string', $data['options']['my_scalar_option']['_schema']['type']);
    }

    public function testExportOptionsSkipsEmptyOptionName(): void
    {
        Functions\when('get_option')->justReturn([]);

        $json = ExportImport::exportOptions(['', 'valid_option']);
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('', $data['options']);
        $this->assertArrayHasKey('valid_option', $data['options']);
    }

    // -------------------------------------------------------------------------
    // importOptions
    // -------------------------------------------------------------------------

    private function makeExportJson(array $options, string $prefix = ''): string
    {
        // Wrap values in typed-node envelope format (new in 1.1.8)
        $wrapped = [];
        foreach ($options as $key => $value) {
            $wrapped[$key] = $this->wrapTypedNode($value);
        }

        return (string) json_encode([
            'version'     => '1.0',
            'type'        => 'hyperfields_export',
            'prefix'      => $prefix,
            'exported_at' => '2024-01-01 00:00:00',
            'site_url'    => 'https://example.com',
            'options'     => $wrapped,
        ]);
    }

    /**
     * Wrap a value in the typed-node envelope format.
     * Mimics ExportImport::wrapTypedNode() for testing.
     */
    private function wrapTypedNode(mixed $value): array
    {
        return [
            'value'   => $value,
            '_schema' => ['type' => $this->detectType($value)],
        ];
    }

    private function detectType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            is_array($value) => 'array',
            is_string($value) => 'string',
            default => 'string',
        };
    }

    public function testImportOptionsSuccess(): void
    {
        $existing = ['key_old' => 'old_val'];
        $incoming = ['key_new' => 'new_val'];

        Functions\when('get_option')->justReturn($existing);
        Functions\when('update_option')->justReturn(true);

        $json = $this->makeExportJson(['my_option' => $incoming]);
        $result = ExportImport::importOptions($json);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('successfully', $result['message']);
    }

    public function testImportOptionsIsAdditive(): void
    {
        $existing = ['existing_key' => 'existing_val'];
        $incoming = ['new_key' => 'new_val'];

        $merged = null;
        Functions\when('get_option')->justReturn($existing);
        Functions\when('update_option')->alias(function (string $name, $value) use (&$merged) {
            $merged = $value;

            return true;
        });

        $json = $this->makeExportJson(['my_option' => $incoming]);
        ExportImport::importOptions($json);

        $this->assertIsArray($merged);
        $this->assertArrayHasKey('existing_key', $merged, 'Existing keys should be preserved (additive)');
        $this->assertArrayHasKey('new_key', $merged, 'New keys should be added');
    }

    public function testImportOptionsWhitelistBlocking(): void
    {
        Functions\when('get_option')->justReturn([]);
        $updateCalled = false;
        Functions\when('update_option')->alias(function () use (&$updateCalled) {
            $updateCalled = true;

            return true;
        });

        $json = $this->makeExportJson(['blocked_option' => ['key' => 'val']]);
        $result = ExportImport::importOptions($json, ['allowed_option']);

        // blocked_option not in whitelist – update_option should NOT be called
        $this->assertFalse($updateCalled);
        // Nothing was imported; result must be false so callers can surface the issue
        $this->assertFalse($result['success']);
    }

    public function testImportOptionsWhitelistAllowing(): void
    {
        Functions\when('get_option')->justReturn([]);
        $updateCalled = false;
        Functions\when('update_option')->alias(function () use (&$updateCalled) {
            $updateCalled = true;

            return true;
        });

        $json = $this->makeExportJson(['allowed_option' => ['key' => 'val']]);
        $result = ExportImport::importOptions($json, ['allowed_option']);

        $this->assertTrue($updateCalled);
        $this->assertTrue($result['success']);
    }

    public function testImportOptionsWithPrefixFilter(): void
    {
        $existingData = ['myplugin_name' => 'old_name', 'other_key' => 'other_val'];
        $incomingData = ['myplugin_name' => 'new_name', 'other_key' => 'should_be_ignored'];

        $merged = null;
        Functions\when('get_option')->justReturn($existingData);
        Functions\when('update_option')->alias(function (string $name, $value) use (&$merged) {
            $merged = $value;

            return true;
        });

        $json = $this->makeExportJson(['my_option' => $incomingData], 'myplugin_');
        ExportImport::importOptions($json, [], 'myplugin_');

        $this->assertIsArray($merged);
        $this->assertSame('new_name', $merged['myplugin_name'], 'Prefix-matched key should be imported');
        $this->assertSame('other_val', $merged['other_key'], 'Non-prefix key should keep its original value');
    }

    public function testImportOptionsReplaceModeForArrayValues(): void
    {
        $existing = ['existing_key' => 'existing_val'];
        $incoming = ['new_key' => 'new_val'];

        $written = null;
        Functions\when('get_option')->justReturn($existing);
        Functions\when('update_option')->alias(function (string $name, $value) use (&$written) {
            $written = $value;

            return true;
        });

        $json = $this->makeExportJson(['my_option' => $incoming]);
        $result = ExportImport::importOptions($json, [], '', ['mode' => 'replace']);

        $this->assertTrue($result['success']);
        $this->assertSame($incoming, $written);
    }

    public function testImportOptionsSupportsScalarValues(): void
    {
        Functions\when('get_option')->justReturn('old_value');
        Functions\when('update_option')->justReturn(true);

        $json = $this->makeExportJson(['my_option' => 'new_value']);
        $result = ExportImport::importOptions($json);

        $this->assertTrue($result['success']);
    }

    public function testImportOptionsInvalidJson(): void
    {
        $result = ExportImport::importOptions('{not valid json}');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid JSON', $result['message']);
    }

    public function testImportOptionsEmptyString(): void
    {
        $result = ExportImport::importOptions('');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Empty', $result['message']);
    }

    public function testImportOptionsMissingOptionsKey(): void
    {
        $json = json_encode(['version' => '1.0', 'type' => 'hyperfields_export']);
        $result = ExportImport::importOptions((string) $json);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"options"', $result['message']);
    }

    public function testImportOptionsCreatesBackup(): void
    {
        $existing = ['key' => 'original_value'];
        Functions\when('get_option')->justReturn($existing);
        Functions\when('update_option')->justReturn(true);

        $transientKey = null;
        Functions\when('set_transient')->alias(function (string $key, $value, $exp) use (&$transientKey) {
            $transientKey = $key;

            return true;
        });

        $json = $this->makeExportJson(['my_option' => ['key' => 'new_value']]);
        $result = ExportImport::importOptions($json);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('backup_keys', $result);
        $this->assertNotNull($transientKey);
    }

    // -------------------------------------------------------------------------
    // restoreBackup
    // -------------------------------------------------------------------------

    public function testRestoreBackupSuccess(): void
    {
        $backup = ['key' => 'backup_value'];
        Functions\when('get_transient')->justReturn($backup);
        Functions\when('get_option')->justReturn(['key' => 'current_value']);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $result = ExportImport::restoreBackup('hf_backup_abc123', 'my_option');

        $this->assertTrue($result);
    }

    public function testRestoreBackupNotFound(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $result = ExportImport::restoreBackup('nonexistent_key', 'my_option');

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // snapshotOptions
    // -------------------------------------------------------------------------

    public function testSnapshotOptions(): void
    {
        Functions\when('get_option')->justReturn(['a' => '1', 'b' => '2']);

        $snapshot = ExportImport::snapshotOptions(['opt1', 'opt2']);

        $this->assertArrayHasKey('opt1', $snapshot);
        $this->assertArrayHasKey('opt2', $snapshot);
    }

    public function testSnapshotOptionsWithPrefix(): void
    {
        Functions\when('get_option')->justReturn([
            'pre_key'   => 'val',
            'other_key' => 'ignored',
        ]);

        $snapshot = ExportImport::snapshotOptions(['opt1'], 'pre_');

        $this->assertArrayHasKey('pre_key', $snapshot['opt1']);
        $this->assertArrayNotHasKey('other_key', $snapshot['opt1']);
    }

    public function testSnapshotOptionsSkipsEmptyName(): void
    {
        Functions\when('get_option')->justReturn(['k' => 'v']);

        $snapshot = ExportImport::snapshotOptions(['', 'real_opt']);

        $this->assertArrayNotHasKey('', $snapshot);
        $this->assertArrayHasKey('real_opt', $snapshot);
    }

    public function testSnapshotOptionsPreservesScalarValue(): void
    {
        Functions\when('get_option')->justReturn('scalar');

        $snapshot = ExportImport::snapshotOptions(['opt1']);

        $this->assertSame('scalar', $snapshot['opt1']);
    }

    // -------------------------------------------------------------------------
    // importOptions — edge-case branches
    // -------------------------------------------------------------------------

    public function testImportOptionsAllowsScalarPayloadAlongsideArrayPayload(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        $json = $this->makeExportJson([
            'good_option' => ['key' => 'val'],
            'scalar_option'  => 'scalar',
        ]);
        $result = ExportImport::importOptions($json);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('successfully', $result['message']);
    }

    public function testImportOptionsSkipsScalarWhenPrefixIsSet(): void
    {
        Functions\when('get_option')->justReturn('old');
        Functions\when('update_option')->justReturn(true);

        $json = $this->makeExportJson(['scalar_option' => 'new']);
        $result = ExportImport::importOptions($json, [], 'myp_');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('scalar values cannot be prefix-filtered', $result['message']);
    }

    public function testDiffOptionsReturnsChanges(): void
    {
        Functions\when('get_option')->justReturn(['existing' => 'old']);

        $json = $this->makeExportJson(['my_option' => ['existing' => 'new']]);
        $result = ExportImport::diffOptions($json);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('my_option', $result['changes']);
    }

    public function testImportOptionsPrefixFiltersOutAllIncomingKeys(): void
    {
        // Incoming has keys but none match the prefix → continue, nothing imported.
        Functions\when('get_option')->justReturn([]);

        $json = $this->makeExportJson(['my_option' => ['other_key' => 'val']]);
        $result = ExportImport::importOptions($json, [], 'myp_');

        $this->assertFalse($result['success']);
    }

    public function testImportOptionsUnchangedValueCountsAsSuccess(): void
    {
        // update_option returns false because value is identical — should still succeed.
        $data = ['key' => 'same_value'];
        Functions\when('get_option')->justReturn($data);
        Functions\when('update_option')->justReturn(false); // unchanged → false

        $json = $this->makeExportJson(['my_option' => $data]);
        $result = ExportImport::importOptions($json);

        $this->assertTrue($result['success']);
    }

    // -------------------------------------------------------------------------
    // restoreBackup — unchanged value path
    // -------------------------------------------------------------------------

    public function testRestoreBackupSuccessWhenValueUnchanged(): void
    {
        // update_option returns false because backup value matches current value.
        $backup = ['key' => 'same'];
        Functions\when('get_transient')->justReturn($backup);
        Functions\when('get_option')->justReturn($backup); // same value
        Functions\when('update_option')->justReturn(false);

        $deleteCalled = false;
        Functions\when('delete_transient')->alias(function () use (&$deleteCalled) {
            $deleteCalled = true;

            return true;
        });

        $result = ExportImport::restoreBackup('hf_backup_abc', 'my_option');

        $this->assertTrue($result, 'Restore should succeed when value is already current');
        $this->assertTrue($deleteCalled, 'Transient must be deleted even when value is unchanged');
    }
}
