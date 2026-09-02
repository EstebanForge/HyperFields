<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Field;
use HyperFields\OptionsPage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the single-field write helper and capability accessor on
 * OptionsPage: the prerequisites behind the hyperfields/update-option
 * ability. The write must ride the same sanitize/validate/pre_save path
 * the Settings-API save uses, narrowed to one field.
 */
final class OptionsPageFieldValueTest extends TestCase
{
    /** @var array<string, mixed> Simulated option storage. */
    private array $options = [];

    /** @var array<int, array<string, mixed>> */
    private array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Shared bootstrap stubs apply_filters as a passthrough; the pre_save
        // veto test needs a real stack. Test-local, restored on tearDown.
        $hooks = [];
        Functions\when('add_filter')->alias(function ($hook, $cb, $priority = 10) use (&$hooks): bool {
            $hooks[$hook][$priority][] = $cb;
            return true;
        });
        Functions\when('apply_filters')->alias(function ($hook, $value, ...$args) use (&$hooks) {
            foreach ($hooks[$hook][10] ?? [] as $cb) {
                $value = $cb($value, ...$args);
            }
            return $value;
        });

        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('is_email')->alias(static fn ($value) => filter_var((string) $value, FILTER_VALIDATE_EMAIL) ? $value : false);
        Functions\when('add_action')->justReturn(null);
        Functions\when('doing_filter')->justReturn(false);
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $this->options[$name] ?? $default;
        });
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->updates[] = ['name' => $name, 'value' => $value];
            $this->options[$name] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        $this->options = [];
        $this->updates = [];
        parent::tearDown();
    }

    private function makePage(): OptionsPage
    {
        $page = OptionsPage::make('Test Options', 'test-page');
        $page->addSection('general', 'General')->addField(
            Field::make('email', 'contact_email', 'Contact')->setDefault('default@example.com')
        );

        return $page;
    }

    public function test_capability_defaults_to_manage_options_and_is_settable(): void
    {
        $page = $this->makePage();
        $this->assertSame('manage_options', $page->getCapability());

        $page->setCapability('edit_pages');
        $this->assertSame('edit_pages', $page->getCapability());
    }

    public function test_set_field_value_sanitizes_and_persists(): void
    {
        $page = $this->makePage();

        $this->assertTrue($page->setFieldValue('contact_email', 'writer@example.com'));
        $this->assertSame('writer@example.com', $this->options['hyperpress_options']['contact_email']);
    }

    public function test_set_field_value_is_idempotent_for_same_value(): void
    {
        $page = $this->makePage();

        $this->assertTrue($page->setFieldValue('contact_email', 'same@example.com'));
        $write_count = count($this->updates);

        // Same value again: success, but no second row write (update_option
        // no-ops on unchanged values and must not read as a failure).
        $this->assertTrue($page->setFieldValue('contact_email', 'same@example.com'));
        $this->assertCount($write_count, $this->updates);
    }

    public function test_set_field_value_rejects_unknown_fields(): void
    {
        $page = $this->makePage();

        $this->assertFalse($page->setFieldValue('missing_field', 'x'));
        $this->assertSame([], $this->updates);
    }

    public function test_set_field_value_runs_wps_validate(): void
    {
        $page = OptionsPage::make('Test Options', 'test-page');
        $page->addSection('general', 'General')->addField(
            Field::make('text', 'locked_field', 'Locked')
                ->setDefault('safe')
                ->addArg('wps_validate', static fn (): bool => false)
        );

        $this->assertFalse($page->setFieldValue('locked_field', 'anything'));
        $this->assertSame([], $this->updates);
    }

    public function test_set_field_value_applies_pre_save_filter(): void
    {
        $page = $this->makePage();

        add_filter('hyperfields/options_page/pre_save', static fn (array $output): array => [
            'contact_email' => 'vetoed@example.com',
        ]);

        $this->assertTrue($page->setFieldValue('contact_email', 'original@example.com'));
        $this->assertSame('vetoed@example.com', $this->options['hyperpress_options']['contact_email']);
    }

    public function test_find_field_and_all_fields_cover_sections(): void
    {
        $page = $this->makePage();
        $page->addSection('advanced', 'Advanced')->addField(
            Field::make('text', 'advanced_field', 'Advanced')
        );

        $this->assertNotNull($page->findField('contact_email'));
        $this->assertNotNull($page->findField('advanced_field'));
        $this->assertNull($page->findField('missing'));

        $this->assertSame(
            ['contact_email', 'advanced_field'],
            array_keys($page->allFields())
        );
    }
}
