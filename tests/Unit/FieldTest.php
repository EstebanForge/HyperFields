<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Field;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class FieldTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Stub WordPress functions that might be needed
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('sanitize_text_field')->alias(function($value) {
            // Mimic WordPress sanitize_text_field behavior
            $value = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $value);
            $value = strip_tags($value);
            $value = trim($value);
            return $value;
        });
        Functions\when('sanitize_email')->alias(function($value) {
            return filter_var($value, FILTER_SANITIZE_EMAIL);
        });
        Functions\when('sanitize_url')->alias(function($value) {
            return filter_var($value, FILTER_SANITIZE_URL);
        });
        Functions\when('is_email')->alias(function($value) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : false;
        });
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->alias(function($value) { return abs((int) $value); });
        Functions\when('apply_filters')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testFieldCreationWithMake()
    {
        $field = Field::make('text', 'field_name', 'Field Label');

        $this->assertEquals('text', $field->getType());
        $this->assertEquals('field_name', $field->getName());
        $this->assertEquals('Field Label', $field->getLabel());
    }

    public function testFieldSettersAndGetters()
    {
        $field = Field::make('email', 'user_email', 'User Email');

        // Test setter methods
        $field->setDefault('user@example.com');
        $field->setPlaceholder('Enter your email');
        $field->setRequired(true);
        $field->setHelp('This is your email address');
        $field->setHtml('<div>Custom HTML</div>');

        // Test getter methods
        $this->assertEquals('email', $field->getType());
        $this->assertEquals('user_email', $field->getName());
        $this->assertEquals('User Email', $field->getLabel());
        $this->assertEquals('user@example.com', $field->getDefault());
        $this->assertEquals('Enter your email', $field->getPlaceholder());
        $this->assertTrue($field->isRequired());
        $this->assertEquals('This is your email address', $field->getHelp());
        $this->assertEquals('<div>Custom HTML</div>', $field->getHtml());
    }

    public function testHtmlContentAlias()
    {
        $field = Field::make('text', 'test_field', 'Test Field');
        $field->setHtmlContent('<span>Custom Content</span>');

        $this->assertEquals('<span>Custom Content</span>', $field->getHtml());
    }

    public function testFieldContexts()
    {
        $field = Field::make('text', 'test_field', 'Test Field');

        // Test different contexts
        $this->assertEquals('post', $field->getContext()); // Default context

        $field->setContext('term');
        $this->assertEquals('term', $field->getContext());

        $field->setContext('user');
        $this->assertEquals('user', $field->getContext());

        $field->setContext('option');
        $this->assertEquals('option', $field->getContext());
    }

    public function testFieldStorageTypes()
    {
        $field = Field::make('text', 'test_field', 'Test Field');

        // Test default storage type
        $this->assertEquals('meta', $field->getStorageType());

        // Test changing storage type
        $field->setStorageType('option');
        $this->assertEquals('option', $field->getStorageType());
    }

    public function testFieldOptions()
    {
        $field = Field::make('select', 'test_select', 'Test Select');

        $options = [
            'option1' => 'Option 1',
            'option2' => 'Option 2',
            'option3' => 'Option 3'
        ];

        $field->setOptions($options);
        $this->assertEquals($options, $field->getOptions());
    }

    public function testToArrayConversion()
    {
        $field = Field::make('text', 'test_field', 'Test Field');

        $field->setDefault('Default value')
              ->setRequired(true)
              ->setPlaceholder('Enter value')
              ->setHelp('Help text');

        $array = $field->toArray();

        $this->assertEquals('text', $array['type']);
        $this->assertEquals('test_field', $array['name']);
        $this->assertEquals('Test Field', $array['label']);
        $this->assertEquals('Default value', $array['default']);
        $this->assertTrue($array['required']);
        $this->assertEquals('Enter value', $array['placeholder']);
        $this->assertEquals('Help text', $array['help']);
    }

    public function testInvalidFieldTypeThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field type: invalid_type');

        Field::make('invalid_type', 'field_name', 'Field Label');
    }

    public function testInvalidFieldNameThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field name: 123-invalid');

        Field::make('text', '123-invalid', 'Field Label');
    }

    public function testValidationRules()
    {
        $field = Field::make('number', 'test_number', 'Test Number');

        $field->setValidation([
            'min' => 1,
            'max' => 100,
            'required' => true
        ]);

        $this->assertEquals([
            'min' => 1,
            'max' => 100,
            'required' => true
        ], $field->getValidation());
    }

    public function testConditionalLogic()
    {
        $field = Field::make('text', 'dependent_field', 'Dependent Field');

        $field->setConditionalLogic([
            'show_when' => [
                'field' => 'parent_field',
                'operator' => 'equals',
                'value' => 'show'
            ]
        ]);

        $this->assertEquals([
            'show_when' => [
                'field' => 'parent_field',
                'operator' => 'equals',
                'value' => 'show'
            ]
        ], $field->getConditionalLogic());
    }

    public function testMultipleValues()
    {
        $field = Field::make('select', 'test_select', 'Test Select');
        
        $this->assertFalse($field->isMultiple());
        
        $field->setMultiple(true);
        $this->assertTrue($field->isMultiple());
    }

    public function testFieldSanitization()
    {
        $field = Field::make('text', 'test_field', 'Test Field');

        // Test basic text sanitization
        $this->assertEquals('clean text', $field->sanitizeValue('<script>alert("xss")</script>clean text'));

        // Test email field sanitization
        $emailField = Field::make('email', 'test_email', 'Test Email');
        $this->assertEquals('user@example.com', $emailField->sanitizeValue('user@example.com'));
    }

    public function testFieldValidation()
    {
        $field = Field::make('text', 'test_field', 'Test Field');

        // Test required validation
        $field->setRequired(true);
        $this->assertFalse($field->validateValue(''));
        $this->assertTrue($field->validateValue('valid value'));

        // Test optional field
        $field->setRequired(false);
        $this->assertTrue($field->validateValue(''));
    }
}