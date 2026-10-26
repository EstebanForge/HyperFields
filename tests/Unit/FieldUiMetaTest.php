<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use HyperFields\Field;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class FieldUiMetaTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testDefaultsAreNullAndFalse()
    {
        $field = Field::make('text', 'heading', 'Heading');

        $data = $field->toArray();

        $this->assertNull($data['tab']);
        $this->assertNull($data['section']);
        $this->assertFalse($data['live']);
    }

    public function testSetTabStoresValueAndChains()
    {
        $field = Field::make('text', 'heading', 'Heading');

        $this->assertSame($field, $field->setTab('Content'));
        $this->assertSame('Content', $field->getTab());
        $this->assertSame('Content', $field->toArray()['tab']);
    }

    public function testSetSectionStoresValueAndChains()
    {
        $field = Field::make('text', 'heading', 'Heading');

        $this->assertSame($field, $field->setSection('Layout'));
        $this->assertSame('Layout', $field->getSection());
        $this->assertSame('Layout', $field->toArray()['section']);
    }

    public function testSetLiveDefaultsTrueAndChains()
    {
        $field = Field::make('text', 'heading', 'Heading');

        $this->assertSame($field, $field->setLive());
        $this->assertTrue($field->isLive());
        $this->assertTrue($field->toArray()['live']);
    }

    public function testSetLiveExplicitFalse()
    {
        $field = Field::make('text', 'heading', 'Heading')->setLive();

        $field->setLive(false);

        $this->assertFalse($field->isLive());
        $this->assertFalse($field->toArray()['live']);
    }

    public function testFieldsWithoutUiMetaStayUnchanged()
    {
        $plain = Field::make('text', 'heading', 'Heading');
        $grouped = Field::make('text', 'name', 'Name')
            ->setTab('Employer')
            ->setSection('Identity')
            ->setLive();

        $plainData = $plain->toArray();
        $this->assertArrayNotHasKey('tab_value_changes_existing_keys', $plainData);
        $this->assertNull($plainData['tab']);

        $groupedData = $grouped->toArray();
        foreach ($plainData as $key => $value) {
            if (in_array($key, ['tab', 'section', 'live'], true)) {
                continue;
            }
            $this->assertArrayHasKey($key, $groupedData);
        }
    }

    public function testMultiselectBlockAttributeIsArrayTyped(): void
    {
        $field = Field::make('multiselect', 'tags', 'Tags');

        $attribute = (new \HyperFields\BlockFieldAdapter($field))->toBlockAttribute();

        $this->assertSame('array', $attribute['type']);
    }
}
