<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\ReactField;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ReactFieldTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Stub WordPress functions
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testReactFieldCreation()
    {
        $field = ReactField::make('test_field', 'Test Field');

        $this->assertInstanceOf(ReactField::class, $field);
        $this->assertEquals('test_field', $field->getName());
        $this->assertEquals('Test Field', $field->getLabel());
    }

    public function testReactFieldExtendsField()
    {
        $field = ReactField::make('test_field', 'Test Field');

        $this->assertInstanceOf(\HyperFields\Field::class, $field);
    }

    public function testSetReactProp()
    {
        $field = ReactField::make('test_field', 'Test Field');

        $field->setReactProp('placeholder', 'Enter text');
        $field->setReactProp('maxLength', 100);
        $field->setReactProp('disabled', true);

        $props = $field->getReactProps();

        $this->assertEquals('Enter text', $props['placeholder']);
        $this->assertEquals(100, $props['maxLength']);
        $this->assertTrue($props['disabled']);
    }

    public function testSetReactComponent()
    {
        $field = ReactField::make('test_field', 'Test Field');

        $field->setReactComponent('CustomTextField');

        $this->assertEquals('CustomTextField', $field->getReactComponent());
    }

    public function testSetUseReact()
    {
        $field = ReactField::make('test_field', 'Test Field');

        // React is enabled by default for ReactField
        $this->assertTrue($field->getUseReact());

        $field->setUseReact(false);
        $this->assertFalse($field->getUseReact());

        $field->setUseReact(true);
        $this->assertTrue($field->getUseReact());
    }

    public function testGetReactComponentForTextField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'text');

        $this->assertEquals('TextField', $field->getReactComponent());
    }

    public function testGetReactComponentForTextareaField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'textarea');

        $this->assertEquals('TextareaField', $field->getReactComponent());
    }

    public function testGetReactComponentForNumberField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'number');

        $this->assertEquals('NumberField', $field->getReactComponent());
    }

    public function testGetReactComponentForEmailField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'email');

        $this->assertEquals('EmailField', $field->getReactComponent());
    }

    public function testGetReactComponentForUrlField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'url');

        $this->assertEquals('UrlField', $field->getReactComponent());
    }

    public function testGetReactComponentForColorField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'color');

        $this->assertEquals('ColorField', $field->getReactComponent());
    }

    public function testGetReactComponentForImageField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'image');

        $this->assertEquals('ImageField', $field->getReactComponent());
    }

    public function testGetReactComponentForCheckboxField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'checkbox');

        $this->assertEquals('CheckboxField', $field->getReactComponent());
    }

    public function testGetReactComponentForSelectField()
    {
        $field = ReactField::make('test_field', 'Test Field', 'select');

        $this->assertEquals('SelectField', $field->getReactComponent());
    }

    public function testGetReactComponentForUnsupportedField()
    {
        // Use a truly unsupported field type (not in the component map)
        $field = ReactField::make('test_field', 'Test Field', 'custom');

        // Unsupported field types default to TextField
        $this->assertEquals('TextField', $field->getReactComponent());
    }

    public function testGetReactPropsIncludesBaseProps()
    {
        $field = ReactField::make('test_field', 'Test Field', 'text')
            ->setHelp('This is help text')
            ->setPlaceholder('Enter value');

        $props = $field->getReactProps();

        $this->assertEquals('test_field', $props['name']);
        $this->assertEquals('Test Field', $props['label']);
        $this->assertEquals('This is help text', $props['help']);
        $this->assertEquals('Enter value', $props['placeholder']);
        $this->assertEquals('text', $props['type']);
    }

    public function testGetReactPropsMergesCustomProps()
    {
        $field = ReactField::make('test_field', 'Test Field')
            ->setReactProp('customProp', 'customValue')
            ->setReactProp('maxLength', 50);

        $props = $field->getReactProps();

        $this->assertEquals('customValue', $props['customProp']);
        $this->assertEquals(50, $props['maxLength']);
        $this->assertEquals('test_field', $props['name']); // Base prop still present
    }

    public function testGetReactPropsWithSelectOptions()
    {
        $field = ReactField::make('test_field', 'Test Field', 'select')
            ->setOptions([
                'option1' => 'Option 1',
                'option2' => 'Option 2',
            ]);

        $props = $field->getReactProps();

        $this->assertEquals([
            'option1' => 'Option 1',
            'option2' => 'Option 2',
        ], $props['options']);
    }

    public function testGetReactPropsWithNumberAttributes()
    {
        $field = ReactField::make('test_field', 'Test Field', 'number')
            ->setReactProp('min', 0)
            ->setReactProp('max', 100)
            ->setReactProp('step', 0.5);

        $props = $field->getReactProps();

        $this->assertEquals(0, $props['min']);
        $this->assertEquals(100, $props['max']);
        $this->assertEquals(0.5, $props['step']);
    }

    public function testReactFieldChaining()
    {
        $field = ReactField::make('test_field', 'Test Field')
            ->setReactProp('placeholder', 'Enter text')
            ->setReactComponent('CustomField')
            ->setUseReact(true);

        $this->assertEquals('Enter text', $field->getReactProps()['placeholder']);
        $this->assertEquals('CustomField', $field->getReactComponent());
        $this->assertTrue($field->getUseReact());
    }

    public function testReactFieldWithFieldSetters()
    {
        $field = ReactField::make('test_field', 'Test Field', 'text')
            ->setHelp('Help text')
            ->setDefault('Default value')
            ->setRequired(true);

        $this->assertEquals('text', $field->getType());
        $this->assertEquals('Help text', $field->getHelp());
        $this->assertEquals('Default value', $field->getDefault());
        $this->assertTrue($field->getRequired());
    }

    public function testReactFieldToArray()
    {
        $field = ReactField::make('test_field', 'Test Field', 'text')
            ->setReactProp('customProp', 'customValue');

        $array = $field->toArray();

        $this->assertEquals('test_field', $array['name']);
        $this->assertEquals('Test Field', $array['label']);
        $this->assertEquals('text', $array['type']);
        // Custom React props should be in reactProps array
        $this->assertEquals('customValue', $array['reactProps']['customProp']);
    }

    public function testReactFieldDisabledStillHasProps()
    {
        $field = ReactField::make('test_field', 'Test Field')
            ->setUseReact(false)
            ->setReactProp('placeholder', 'Enter text');

        $this->assertFalse($field->getUseReact());
        $this->assertEquals('Enter text', $field->getReactProps()['placeholder']);
    }

    public function testMultipleReactProps()
    {
        $field = ReactField::make('test_field', 'Test Field');

        $field->setReactProp('prop1', 'value1');
        $field->setReactProp('prop2', 'value2');
        $field->setReactProp('prop3', 'value3');

        $props = $field->getReactProps();

        $this->assertEquals('value1', $props['prop1']);
        $this->assertEquals('value2', $props['prop2']);
        $this->assertEquals('value3', $props['prop3']);
    }

    public function testReactPropOverwrites()
    {
        $field = ReactField::make('test_field', 'Test Field');

        $field->setReactProp('placeholder', 'First value');
        $field->setReactProp('placeholder', 'Second value');

        $this->assertEquals('Second value', $field->getReactProps()['placeholder']);
    }
}
