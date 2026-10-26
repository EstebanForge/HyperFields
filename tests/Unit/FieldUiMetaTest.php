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

    public function testSetConditionalLogicCanonicalizesToGroupsShape()
    {
        $field = Field::make('select', 'layout', 'Layout')
            ->setOptions(['boxed' => 'Boxed', 'full' => 'Full'])
            ->setConditionalLogic([
                ['field' => 'show_border', 'operator' => '=', 'value' => '1'],
            ]);

        $logic = $field->toArray()['conditional_logic'];

        $this->assertSame([
            'relation' => 'AND',
            'conditions' => [['field' => 'show_border', 'operator' => '=', 'value' => '1']],
            'groups' => [[['field' => 'show_border', 'operator' => '=', 'value' => '1']]],
        ], $logic);
    }

    public function testSetConditionalLogicKeepsCanonicalInput()
    {
        $field = Field::make('text', 'a', 'A');

        $field->setConditionalLogic([
            'relation' => 'OR',
            'conditions' => [
                ['field' => 'a', 'operator' => '=', 'value' => '1'],
            ],
            'groups' => [
                [['field' => 'a', 'operator' => '=', 'value' => '1']],
                [['field' => 'b', 'operator' => '=', 'value' => '2']],
            ],
        ]);

        $this->assertSame('OR', $field->toArray()['conditional_logic']['relation']);
    }

    public function testOptionsMapNormalizesToValueLabelList()
    {
        $field = Field::make('select', 'level', 'Level')
            ->setOptions(['off' => 'Off', 'on' => 'On']);

        $this->assertSame([
            ['value' => 'off', 'label' => 'Off'],
            ['value' => 'on', 'label' => 'On'],
        ], $field->toArray()['options']);
    }

    public function testOptionsSparseIntMapNormalizesToValueLabelList()
    {
        $field = Field::make('select', 'level', 'Level')
            ->setOptions([10 => 'Ten', 20 => 'Twenty']);

        $this->assertSame([
            ['value' => '10', 'label' => 'Ten'],
            ['value' => '20', 'label' => 'Twenty'],
        ], $field->toArray()['options']);
    }

    public function testOptionsScalarListStaysList()
    {
        // A 0-based sequential integer map and a scalar list are the same
        // PHP value; both encode value=label by design.
        $field = Field::make('select', 'tone', 'Tone')
            ->setOptions(['Red', 'Green']);

        $this->assertSame(['Red', 'Green'], $field->toArray()['options']);
    }

    public function testFieldsWithoutUiMetaStayUnchanged()
    {
        $plain = Field::make('text', 'heading', 'Heading');
        $grouped = Field::make('text', 'name', 'Name')
            ->setTab('Employer')
            ->setSection('Identity')
            ->setLive();

        $plainData = $plain->toArray();
        $this->assertNull($plainData['tab']);
        $this->assertNull($plainData['section']);

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
