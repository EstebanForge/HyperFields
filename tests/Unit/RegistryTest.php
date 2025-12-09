<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Registry;
use HyperFields\Field;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class RegistryTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Stub WordPress functions
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('wp_register_script')->justReturn('');
        Functions\when('wp_register_style')->justReturn('');
        Functions\when('wp_enqueue_script')->justReturn('');
        Functions\when('wp_enqueue_style')->justReturn('');
        Functions\when('plugin_dir_url')->justReturn('http://example.com/wp-content/plugins/hyperfields/');
        Functions\when('plugin_dir_path')->justReturn('/path/to/hyperfields/');
    }

    protected function tearDown(): void
    {
        // Clear the registry singleton between tests
        Registry::getInstance()->clear();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testRegistrySingleton()
    {
        $instance1 = Registry::getInstance();
        $instance2 = Registry::getInstance();

        $this->assertSame($instance1, $instance2);
        $this->assertInstanceOf(Registry::class, $instance1);
    }

    public function testRegisterField()
    {
        $registry = Registry::getInstance();
        $field = Field::make('text', 'test_field', 'Test Field');

        $registry->registerField('test_container', $field);

        $registeredFields = $registry->getFields('test_container');
        $this->assertCount(1, $registeredFields);
        $this->assertSame($field, $registeredFields[0]);
    }

    public function testRegisterMultipleFields()
    {
        $registry = Registry::getInstance();
        $field1 = Field::make('text', 'field1', 'Field 1');
        $field2 = Field::make('email', 'field2', 'Field 2');

        $registry->registerField('test_container', $field1);
        $registry->registerField('test_container', $field2);

        $registeredFields = $registry->getFields('test_container');
        $this->assertCount(2, $registeredFields);
        $this->assertSame($field1, $registeredFields[0]);
        $this->assertSame($field2, $registeredFields[1]);
    }

    public function testGetFieldsForNonExistentContainer()
    {
        $registry = Registry::getInstance();

        $fields = $registry->getFields('non_existent_container');
        $this->assertEmpty($fields);
        $this->assertIsArray($fields);
    }

    public function testGetAllFields()
    {
        $registry = Registry::getInstance();
        $field1 = Field::make('text', 'field1', 'Field 1');
        $field2 = Field::make('email', 'field2', 'Field 2');

        $registry->registerField('container1', $field1);
        $registry->registerField('container2', $field2);

        $allFields = $registry->getAllFields();

        $this->assertArrayHasKey('container1', $allFields);
        $this->assertArrayHasKey('container2', $allFields);
        $this->assertCount(1, $allFields['container1']);
        $this->assertCount(1, $allFields['container2']);
        $this->assertSame($field1, $allFields['container1']['field1']);
        $this->assertSame($field2, $allFields['container2']['field2']);
    }

    public function testContainerExists()
    {
        $registry = Registry::getInstance();
        $field = Field::make('text', 'test_field', 'Test Field');

        $this->assertFalse($registry->containerExists('test_container'));

        $registry->registerField('test_container', $field);

        $this->assertTrue($registry->containerExists('test_container'));
    }

    public function testRemoveContainer()
    {
        $registry = Registry::getInstance();
        $field = Field::make('text', 'test_field', 'Test Field');

        $registry->registerField('test_container', $field);
        $this->assertTrue($registry->containerExists('test_container'));

        $registry->removeContainer('test_container');
        $this->assertFalse($registry->containerExists('test_container'));
    }

    public function testRemoveNonExistentContainer()
    {
        $registry = Registry::getInstance();

        // Should not throw an exception
        $registry->removeContainer('non_existent_container');
        $this->assertFalse($registry->containerExists('non_existent_container'));
    }

    public function testClearRegistry()
    {
        $registry = Registry::getInstance();
        $field1 = Field::make('text', 'field1', 'Field 1');
        $field2 = Field::make('email', 'field2', 'Field 2');

        $registry->registerField('container1', $field1);
        $registry->registerField('container2', $field2);

        $this->assertTrue($registry->containerExists('container1'));
        $this->assertTrue($registry->containerExists('container2'));

        $registry->clear();

        $this->assertFalse($registry->containerExists('container1'));
        $this->assertFalse($registry->containerExists('container2'));
        $this->assertEmpty($registry->getAllFields());
    }
}