<?php

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the options-shape contract between
 * Field::toArray() (which normalizes assoc option arrays into
 * ['value','label'] lists) and the field templates.
 *
 * Templates must render BOTH shapes: the normalized list produced by
 * toArray() and any raw assoc array passed straight through.
 */
final class FieldOptionsTemplatesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html_e')->returnArg();
        Functions\when('esc_attr_e')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('checked')->alias(static function ($checked, $current = true, $display = true): string {
            $result = ((string) $checked === (string) $current) ? ' checked=\'checked\'' : '';
            if ($display) {
                echo $result;
            }

            return $result;
        });
        Functions\when('selected')->alias(static function ($selected, $current = true, $display = true): string {
            $result = ((string) $selected === (string) $current) ? ' selected=\'selected\'' : '';
            if ($display) {
                echo $result;
            }

            return $result;
        });
        Functions\when('__')->returnArg();
        Functions\when('_e')->returnArg();
        Functions\when('esc_attr__')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Render a template with a $field_data bag, returning its output.
     *
     * @param array<string, mixed> $fieldData
     * @param string $template Template file base name.
     */
    private function render(array $fieldData, string $template): string
    {
        $field_data = $fieldData;
        ob_start();
        require dirname(__DIR__, 2) . '/src/templates/' . $template . '.php';

        return (string) ob_get_clean();
    }

    public function testSelectRendersNormalizedValueLabelOptions(): void
    {
        $html = $this->render([
            'type' => 'select',
            'name' => 'freq',
            'name_attr' => 'settings[freq]',
            'options' => [
                ['value' => 'daily', 'label' => 'Daily'],
                ['value' => 'weekly', 'label' => 'Weekly'],
            ],
            'value' => 'weekly',
        ], 'field-input');

        $this->assertStringContainsString('Daily', $html);
        $this->assertStringContainsString('Weekly', $html);
        $this->assertStringContainsString('value="weekly"', $html);
        $this->assertStringContainsString('selected=\'selected\'', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testSelectStillRendersRawAssocOptions(): void
    {
        $html = $this->render([
            'type' => 'select',
            'name' => 'freq',
            'name_attr' => 'settings[freq]',
            'options' => ['daily' => 'Daily', 'weekly' => 'Weekly'],
            'value' => 'daily',
        ], 'field-input');

        $this->assertStringContainsString('Daily', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testMultiselectRendersNormalizedValueLabelOptions(): void
    {
        $html = $this->render([
            'type' => 'multiselect',
            'name' => 'blocked',
            'name_attr' => 'settings[blocked]',
            'options' => [
                ['value' => 'hello', 'label' => 'Hello Dolly'],
                ['value' => 'akismet', 'label' => 'Akismet'],
            ],
            'value' => ['hello'],
        ], 'field-multiselect');

        $this->assertStringContainsString('Hello Dolly', $html);
        $this->assertStringContainsString('Akismet', $html);
        $this->assertStringContainsString('value="hello"', $html);
        $this->assertStringContainsString('data-value="hello"', $html);
        $this->assertStringContainsString('selected=\'selected\'', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testRadioRendersNormalizedValueLabelOptions(): void
    {
        $html = $this->render([
            'type' => 'radio',
            'name' => 'freq',
            'name_attr' => 'settings[freq]',
            'options' => [
                ['value' => 'daily', 'label' => 'Daily'],
                ['value' => 'weekly', 'label' => 'Weekly'],
            ],
            'value' => 'daily',
        ], 'field-radio');

        $this->assertStringContainsString('Daily', $html);
        $this->assertStringContainsString('Weekly', $html);
        $this->assertStringContainsString('value="daily"', $html);
        $this->assertStringContainsString('checked=\'checked\'', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testMultiselectStillRendersRawAssocOptions(): void
    {
        $html = $this->render([
            'type' => 'multiselect',
            'name' => 'blocked',
            'name_attr' => 'settings[blocked]',
            'options' => ['hello' => 'Hello Dolly', 'akismet' => 'Akismet'],
            'value' => ['hello'],
        ], 'field-multiselect');

        $this->assertStringContainsString('value="hello"', $html);
        $this->assertStringContainsString('data-value="hello"', $html);
        $this->assertStringContainsString('selected=\'selected\'', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testRadioStillRendersRawAssocOptions(): void
    {
        $html = $this->render([
            'type' => 'radio',
            'name' => 'freq',
            'name_attr' => 'settings[freq]',
            'options' => ['daily' => 'Daily', 'weekly' => 'Weekly'],
            'value' => 'daily',
        ], 'field-radio');

        $this->assertStringContainsString('value="daily"', $html);
        $this->assertStringContainsString('checked=\'checked\'', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testGenericInputRendersNormalizedRadioOptions(): void
    {
        $html = $this->render([
            'type' => 'radio',
            'name' => 'freq',
            'name_attr' => 'settings[freq]',
            'options' => [
                ['value' => 'daily', 'label' => 'Daily'],
                ['value' => 'weekly', 'label' => 'Weekly'],
            ],
            'value' => 'daily',
        ], 'field-input');

        $this->assertStringContainsString('value="daily"', $html);
        $this->assertStringContainsString('checked=\'checked\'', $html);
        $this->assertStringContainsString('Daily', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testGenericInputRendersNormalizedSetOptions(): void
    {
        $html = $this->render([
            'type' => 'set',
            'name' => 'roles',
            'name_attr' => 'settings[roles]',
            'options' => [
                ['value' => 'editor', 'label' => 'Editor'],
                ['value' => 'author', 'label' => 'Author'],
            ],
            'value' => ['editor'],
        ], 'field-input');

        $this->assertStringContainsString('value="editor"', $html);
        $this->assertStringContainsString('checked=\'checked\'', $html);
        $this->assertStringContainsString('Editor', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function testSetTemplateRendersNormalizedOptions(): void
    {
        $html = $this->render([
            'type' => 'set',
            'name' => 'roles',
            'name_attr' => 'settings[roles]',
            'options' => [
                ['value' => 'editor', 'label' => 'Editor'],
                ['value' => 'author', 'label' => 'Author'],
            ],
            'value' => ['editor'],
        ], 'field-set');

        $this->assertStringContainsString('value="editor"', $html);
        $this->assertStringContainsString('checked=\'checked\'', $html);
        $this->assertStringContainsString('Editor', $html);
        $this->assertStringNotContainsString('Array', $html);
    }
}
