<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Field;
use HyperFields\RepeaterField;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Field::toJsonSchema(): the machine-readable export behind the
 * Abilities layer.
 */
final class FieldJsonSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Shared bootstrap stubs apply_filters as a passthrough; the escape-
        // hatch test needs a real stack. Test-local, restored on tearDown.
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
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_email_field_gets_format(): void
    {
        $schema = Field::make('email', 'contact', 'Contact')->toJsonSchema();

        $this->assertSame('string', $schema['type']);
        $this->assertSame('email', $schema['format']);
        $this->assertSame('Contact', $schema['description']);
    }

    public function test_url_field_gets_uri_format(): void
    {
        $schema = Field::make('url', 'link', 'Link')->toJsonSchema();

        $this->assertSame('uri', $schema['format']);
    }

    public function test_select_field_gets_enum_from_option_keys(): void
    {
        $schema = Field::make('select', 'layout', 'Layout')
            ->setOptions(['boxed' => 'Boxed', 'full' => 'Full Width'])
            ->toJsonSchema();

        $this->assertSame('string', $schema['type']);
        $this->assertSame(['boxed', 'full'], $schema['enum']);
    }

    public function test_multiselect_field_gets_array_of_enum(): void
    {
        $schema = Field::make('multiselect', 'tags', 'Tags')
            ->setOptions(['a' => 'A', 'b' => 'B'])
            ->toJsonSchema();

        $this->assertSame('array', $schema['type']);
        $this->assertSame(['a', 'b'], $schema['items']['enum']);
    }

    public function test_checkbox_field_gets_boolean(): void
    {
        $schema = Field::make('checkbox', 'enabled', 'Enabled')->toJsonSchema();

        $this->assertSame('boolean', $schema['type']);
    }

    public function test_number_field_gets_bounds(): void
    {
        $schema = Field::make('number', 'level', 'Level')
            ->setMin(1.0)
            ->setMax(5.0)
            ->toJsonSchema();

        $this->assertSame('number', $schema['type']);
        $this->assertSame(1.0, $schema['minimum']);
        $this->assertSame(5.0, $schema['maximum']);
    }

    public function test_default_value_is_included_when_set(): void
    {
        $schema = Field::make('text', 'who', 'Who')->setDefault('me')->toJsonSchema();

        $this->assertSame('me', $schema['default']);
    }

    public function test_repeater_field_builds_object_items_from_sub_fields(): void
    {
        $repeater = RepeaterField::make('rows', 'Rows')
            ->addSubField(Field::make('email', 'sub_email', 'Sub Email'))
            ->addSubField(Field::make('separator', 'divider', 'Divider'));

        $schema = $repeater->toJsonSchema();

        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
        // Non-schema sub-fields are omitted from the object shape.
        $this->assertSame(['sub_email'], array_keys($schema['items']['properties']));
        $this->assertSame('email', $schema['items']['properties']['sub_email']['format']);
    }

    public function test_structural_types_have_no_schema(): void
    {
        foreach (['tabs', 'heading', 'separator', 'hidden', 'custom'] as $type) {
            $this->assertNull(Field::make($type, 'x_' . $type, 'X')->toJsonSchema(), $type);
        }
    }

    public function test_filter_is_the_escape_hatch_for_null_schemas(): void
    {
        add_filter('hyperfields/field/json_schema', static fn (): array => ['type' => 'string']);

        $schema = Field::make('separator', 'divider', 'Divider')->toJsonSchema();

        $this->assertSame(['type' => 'string'], $schema);
    }
}
