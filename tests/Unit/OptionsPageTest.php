<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\OptionsPage;
use HyperFields\OptionsSection;
use HyperFields\Field;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class OptionsPageTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    private OptionsPage $page;
    private $templateLoaderMock;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Stub WordPress functions
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('add_query_arg')->justReturn('http://example.com/page');
        Functions\when('admin_url')->returnArg();
        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_create_nonce')->justReturn('test_nonce');
        Functions\when('settings_fields')->justReturn('');
        Functions\when('do_settings_fields')->justReturn('');
        Functions\when('submit_button')->justReturn('<button>Submit</button>');

        $this->templateLoaderMock = \Mockery::mock('alias:HyperFields\TemplateLoader');

        // Use reflection to access private constructor
        $reflection = new \ReflectionClass(OptionsPage::class);
        $constructor = $reflection->getConstructor();
        $this->page = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($this->page, 'Test Page', 'test-page');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testStaticMakeMethod()
    {
        $page = OptionsPage::make('Static Page', 'static-page');

        $this->assertInstanceOf(OptionsPage::class, $page);

        $reflection = new \ReflectionClass($page);
        $pageTitle = $reflection->getProperty('page_title');
        $this->assertEquals('Static Page', $pageTitle->getValue($page));
    }

    public function testPageCreation()
    {
        $reflection = new \ReflectionClass($this->page);

        $pageTitle = $reflection->getProperty('page_title');
        $this->assertEquals('Test Page', $pageTitle->getValue($this->page));

        $menuTitle = $reflection->getProperty('menu_title');
        $this->assertEquals('Test Page', $menuTitle->getValue($this->page));

        $menuSlug = $reflection->getProperty('menu_slug');
        $this->assertEquals('test-page', $menuSlug->getValue($this->page));

        $capability = $reflection->getProperty('capability');
        $this->assertEquals('manage_options', $capability->getValue($this->page));

        $parentSlug = $reflection->getProperty('parent_slug');
        $this->assertEquals('options-general.php', $parentSlug->getValue($this->page));
    }

    public function testSetMenuTitle()
    {
        $this->page->setMenuTitle('Custom Menu Title');

        $reflection = new \ReflectionClass($this->page);
        $menuTitle = $reflection->getProperty('menu_title');
        $this->assertEquals('Custom Menu Title', $menuTitle->getValue($this->page));
    }

    public function testSetCapability()
    {
        $this->page->setCapability('edit_posts');

        $reflection = new \ReflectionClass($this->page);
        $capability = $reflection->getProperty('capability');
        $this->assertEquals('edit_posts', $capability->getValue($this->page));
    }

    public function testSetParentSlug()
    {
        $this->page->setParentSlug('custom-parent');

        $reflection = new \ReflectionClass($this->page);
        $parentSlug = $reflection->getProperty('parent_slug');
        $this->assertEquals('custom-parent', $parentSlug->getValue($this->page));
    }

    public function testSetIconUrl()
    {
        $this->page->setIconUrl('dashicons-admin-tools');

        $reflection = new \ReflectionClass($this->page);
        $iconUrl = $reflection->getProperty('icon_url');
        $this->assertEquals('dashicons-admin-tools', $iconUrl->getValue($this->page));
    }

    public function testSetPosition()
    {
        $this->page->setPosition(25);

        $reflection = new \ReflectionClass($this->page);
        $position = $reflection->getProperty('position');
        $this->assertEquals(25, $position->getValue($this->page));
    }

    public function testSetOptionName()
    {
        $this->page->setOptionName('custom_options');

        $this->assertEquals('custom_options', $this->page->getOptionName());
    }

    public function testSetFooterContent()
    {
        $this->page->setFooterContent('<p>Footer content</p>');

        $reflection = new \ReflectionClass($this->page);
        $footerContent = $reflection->getProperty('footer_content');
        $this->assertEquals('<p>Footer content</p>', $footerContent->getValue($this->page));
    }

    public function testAddSection()
    {
        $section = $this->page->addSection('test_section', 'Test Section', 'Test description');

        $this->assertInstanceOf(OptionsSection::class, $section);
        $this->assertEquals('test_section', $section->getId());

        $reflection = new \ReflectionClass($this->page);
        $sections = $reflection->getProperty('sections');
        $sectionsArray = $sections->getValue($this->page);

        $this->assertArrayHasKey('test_section', $sectionsArray);
        $this->assertSame($section, $sectionsArray['test_section']);
    }

    public function testAddSectionObject()
    {
        $section = new OptionsSection('custom_section', 'Custom Section');
        $field = Field::make('text', 'test_field', 'Test Field')->setDefault('default_value');
        $section->addField($field);

        $result = $this->page->addSectionObject($section);

        $this->assertSame($this->page, $result);

        $reflection = new \ReflectionClass($this->page);
        $sections = $reflection->getProperty('sections');
        $sectionsArray = $sections->getValue($this->page);
        $this->assertArrayHasKey('custom_section', $sectionsArray);

        $defaultValues = $reflection->getProperty('default_values');
        $defaultValuesArray = $defaultValues->getValue($this->page);
        $this->assertArrayHasKey('test_field', $defaultValuesArray);
        $this->assertEquals('default_value', $defaultValuesArray['test_field']);
    }

    public function testAddField()
    {
        $field = Field::make('text', 'test_field', 'Test Field');

        $result = $this->page->addField($field);

        $this->assertSame($this->page, $result);

        $reflection = new \ReflectionClass($this->page);
        $fields = $reflection->getProperty('fields');
        $fieldsArray = $fields->getValue($this->page);
        $this->assertArrayHasKey('test_field', $fieldsArray);
    }

    public function testRegister()
    {
        Functions\expect('add_action')->once()->with('admin_menu', \Mockery::type('callable'));
        Functions\expect('add_action')->once()->with('admin_init', \Mockery::type('callable'));
        Functions\expect('add_action')->once()->with('admin_enqueue_scripts', \Mockery::type('callable'));

        $this->page->register();
    }

    public function testLoadOptions()
    {
        // Use when to override the default stub from setUp
        Functions\when('get_option')
            ->justReturn(['field1' => 'value1']);

        $this->page->addSection('section1', 'Section 1');
        $this->page->addField(Field::make('text', 'field1', 'Field 1')->setDefault('default1'));

        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('loadOptions');
        $method->invoke($this->page);

        $optionValues = $reflection->getProperty('option_values');
        $values = $optionValues->getValue($this->page);
        
        $this->assertArrayHasKey('field1', $values);
        $this->assertEquals('value1', $values['field1']);
    }

    public function testAddMenuPageWithParent()
    {
        $this->page->setParentSlug('options-general.php');

        Functions\expect('add_submenu_page')
            ->once()
            ->with(
                'options-general.php',
                'Test Page',
                'Test Page',
                'manage_options',
                'test-page',
                [$this->page, 'renderPage'],
                null
            );

        $this->page->addMenuPage();
    }

    public function testAddMenuPageAsTopLevel()
    {
        $this->page->setParentSlug('menu');

        Functions\expect('add_menu_page')
            ->once()
            ->with(
                'Test Page',
                'Test Page',
                'manage_options',
                'test-page',
                [$this->page, 'renderPage'],
                '',
                null
            );

        $this->page->addMenuPage();
    }

    public function testRegisterSettings()
    {
        $section = $this->page->addSection('test_section', 'Test Section');
        $field = Field::make('text', 'test_field', 'Test Field');
        $section->addField($field);

        Functions\expect('register_setting')
            ->once()
            ->with(
                'hyperpress_options',
                'hyperpress_options',
                ['sanitize_callback' => [$this->page, 'sanitizeOptions']]
            );

        Functions\expect('add_settings_section')
            ->once()
            ->with('test_section', '', '__return_false', 'hyperpress_options');

        Functions\expect('add_settings_field')
            ->once()
            ->with('test_field', '', [$field, 'render'], 'hyperpress_options', 'test_section', $field->getArgs());

        $this->page->registerSettings();
    }

    public function testSanitizeOptions()
    {
        $section = $this->page->addSection('test_section', 'Test Section');
        $textField = Field::make('text', 'text_field', 'Text Field');
        $checkboxField = Field::make('checkbox', 'checkbox_field', 'Checkbox Field');
        $section->addField($textField);
        $section->addField($checkboxField);

        $_POST['hyperpress_active_tab'] = 'test_section';
        $input = ['text_field' => 'sanitized text'];

        $result = $this->page->sanitizeOptions($input);

        $this->assertArrayHasKey('text_field', $result);
        $this->assertEquals('sanitized text', $result['text_field']);
        $this->assertArrayHasKey('checkbox_field', $result);
        $this->assertEquals('0', $result['checkbox_field']); // Unchecked checkbox
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSanitizeOptionsWithCompactInput()
    {
        if (!defined('HYPERPRESS_COMPACT_INPUT')) {
            define('HYPERPRESS_COMPACT_INPUT', true);
        }

        $_POST['hyperpress_compact_input'] = json_encode([
            'hyperpress_options' => ['text_field' => 'compact_value']
        ]);
        $_POST['hyperpress_active_tab'] = 'test_section';

        $section = $this->page->addSection('test_section', 'Test Section');
        $section->addField(Field::make('text', 'text_field', 'Text Field'));

        $result = $this->page->sanitizeOptions([]);

        $this->assertEquals('compact_value', $result['text_field']);
    }

    public function testGetActiveTabFromPost()
    {
        $this->page->addSection('section1', 'Section 1');
        $this->page->addSection('section2', 'Section 2');

        $_POST['hyperpress_active_tab'] = 'section2';

        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $result = $method->invoke($this->page);

        $this->assertEquals('section2', $result);
    }

    public function testGetActiveTabFromGet()
    {
        $this->page->addSection('section1', 'Section 1');
        $this->page->addSection('section2', 'Section 2');

        $_GET['tab'] = 'section1';

        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $result = $method->invoke($this->page);

        $this->assertEquals('section1', $result);
    }

    public function testGetActiveTabDefault()
    {
        $this->page->addSection('section1', 'Section 1');
        $this->page->addSection('section2', 'Section 2');

        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $result = $method->invoke($this->page);

        $this->assertEquals('section1', $result);
    }

    public function testGetActiveTabNoSections()
    {
        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $result = $method->invoke($this->page);

        $this->assertEquals('main', $result);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testEnqueueAssets()
    {
        if (!defined('HYPERPRESS_PLUGIN_URL')) {
            define('HYPERPRESS_PLUGIN_URL', 'http://example.com/plugin/');
        }
        if (!defined('HYPERPRESS_VERSION')) {
            define('HYPERPRESS_VERSION', '2.0.7');
        }

        $this->templateLoaderMock->shouldReceive('enqueueAssets')->once();

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'hyperpress-admin-options',
                'http://example.com/plugin/assets/js/admin-options.js',
                ['jquery'],
                '2.0.7',
                true
            );

        Functions\expect('wp_localize_script')
            ->once()
            ->with(
                'hyperpress-admin-options',
                'hyperpressOptions',
                \Mockery::type('array')
            );

        $this->page->enqueueAssets('settings_page_test-page');
    }

    public function testEnqueueAssetsWrongPage()
    {
        $this->templateLoaderMock->shouldNotReceive('enqueueAssets');

        $this->page->enqueueAssets('wrong_page');
    }

    public function testRenderPage()
    {
        Functions\when('esc_attr')->returnArg();
        $this->page->addSection('test_section', 'Test Section', 'Test Description');

        // Stub settings fields to avoid errors
        Functions\when('settings_fields')->justReturn('');
        Functions\when('do_settings_fields')->justReturn('');

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString('wrap', $output);
        $this->assertStringContainsString('Test Page', $output);
        $this->assertStringContainsString('nav-tab-wrapper', $output);
        $this->assertStringContainsString('Test Section', $output);
        $this->assertStringContainsString('Test Description', $output);
    }

    public function testRenderPageWithFooter()
    {
        Functions\when('esc_attr')->returnArg();
        $this->page->setFooterContent('<p>Custom footer</p>');
        $this->page->addSection('main_section', 'Main Section');

        Functions\when('settings_fields')->justReturn('');
        Functions\when('do_settings_fields')->justReturn('');

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString('Custom footer', $output);
        $this->assertStringContainsString('hyperpress-options-footer', $output);
    }

    public function testFluentInterface()
    {
        $result = $this->page->setMenuTitle('Custom Title')
                           ->setCapability('edit_posts')
                           ->setParentSlug('custom-parent')
                           ->setIconUrl('dashicon')
                           ->setPosition(25);

        $this->assertSame($this->page, $result);
    }
}