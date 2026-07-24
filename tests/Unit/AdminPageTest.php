<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\AdminPage;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AdminPageTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    private AdminPage $page;

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
        Functions\when('wp_unslash')->returnArg();
        Functions\when('add_query_arg')->justReturn('http://example.com/page');
        Functions\when('admin_url')->returnArg();
        Functions\when('wp_create_nonce')->justReturn('test_nonce');

        // Use reflection to bypass the private constructor
        $reflection = new \ReflectionClass(AdminPage::class);
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
        $page = AdminPage::make('Static Page', 'static-page');

        $this->assertInstanceOf(AdminPage::class, $page);

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
        $this->page->setMenuTitle('Custom Title');

        $reflection = new \ReflectionClass($this->page);
        $menuTitle = $reflection->getProperty('menu_title');
        $this->assertEquals('Custom Title', $menuTitle->getValue($this->page));
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
        $this->page->setIconUrl('dashicon');

        $reflection = new \ReflectionClass($this->page);
        $iconUrl = $reflection->getProperty('icon_url');
        $this->assertEquals('dashicon', $iconUrl->getValue($this->page));
    }

    public function testSetPosition()
    {
        $this->page->setPosition(25);

        $reflection = new \ReflectionClass($this->page);
        $position = $reflection->getProperty('position');
        $this->assertEquals(25, $position->getValue($this->page));
    }

    public function testSetFooterContent()
    {
        $this->page->setFooterContent('<p>Footer</p>');

        $reflection = new \ReflectionClass($this->page);
        $footer = $reflection->getProperty('footer_content');
        $this->assertEquals('<p>Footer</p>', $footer->getValue($this->page));
    }

    public function testAddTab()
    {
        $this->page->addTab('upload', 'Upload', static function () {});

        $reflection = new \ReflectionClass($this->page);
        $tabs = $reflection->getProperty('tabs');
        $value = $tabs->getValue($this->page);

        $this->assertArrayHasKey('upload', $value);
        $this->assertEquals('Upload', $value['upload']['title']);
        $this->assertIsCallable($value['upload']['render']);
    }

    public function testAddTabDoesNotDuplicateExistingId()
    {
        $first = static function () {
            echo 'first';
        };
        $second = static function () {
            echo 'second';
        };
        $this->page->addTab('upload', 'Upload', $first);
        $this->page->addTab('upload', 'Ignored', $second);

        $reflection = new \ReflectionClass($this->page);
        $tabs = $reflection->getProperty('tabs');
        $value = $tabs->getValue($this->page);

        $this->assertCount(1, $value);
        $this->assertEquals('Upload', $value['upload']['title']);
        $this->assertSame($first, $value['upload']['render']);
    }

    public function testRegisterHooksAdminMenuAndEnqueue()
    {
        Functions\expect('add_action')->once()->with('admin_menu', \Mockery::type('callable'));
        Functions\expect('add_action')->once()->with('admin_enqueue_scripts', \Mockery::type('callable'));

        $this->page->register();
    }

    public function testRegisterDuringAdminMenuHookCallsAddMenuPageDirectly()
    {
        Functions\expect('doing_filter')->once()->with('admin_menu')->andReturn(true);

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

        Functions\expect('add_action')->once()->with('admin_enqueue_scripts', \Mockery::type('callable'));
        Functions\expect('add_action')->never()->with('admin_menu', \Mockery::type('callable'));

        $this->page->register();
    }

    public function testRegisterHasNoAdminInitHook()
    {
        // AdminPage does NOT register settings (no register_setting / sanitize),
        // so it must not hook admin_init like OptionsPage does.
        Functions\expect('doing_filter')->once()->with('admin_menu')->andReturn(false);
        Functions\expect('add_action')->never()->with('admin_init', \Mockery::type('callable'));

        $this->page->register();
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

    public function testGetActiveTabFromGet()
    {
        $this->page->addTab('upload', 'Upload', static function () {});
        $this->page->addTab('history', 'History', static function () {});

        $_GET['tab'] = 'history';

        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $this->assertEquals('history', $method->invoke($this->page));
    }

    public function testGetActiveTabIgnoresUnknownTab()
    {
        $this->page->addTab('upload', 'Upload', static function () {});
        $this->page->addTab('history', 'History', static function () {});

        $_GET['tab'] = 'does-not-exist';

        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $this->assertEquals('upload', $method->invoke($this->page));
    }

    public function testGetActiveTabDefaultsToFirst()
    {
        $this->page->addTab('upload', 'Upload', static function () {});
        $this->page->addTab('history', 'History', static function () {});

        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $this->assertEquals('upload', $method->invoke($this->page));
    }

    public function testGetActiveTabFallbackMainWhenNoTabs()
    {
        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('getActiveTab');

        $this->assertEquals('main', $method->invoke($this->page));
    }

    public function testRenderTabsEmptyWhenNoTabs()
    {
        $reflection = new \ReflectionClass($this->page);
        $method = $reflection->getMethod('renderTabs');

        ob_start();
        $method->invoke($this->page);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testRenderPageEmitsChromeAndTabContent()
    {
        $this->page->addTab('upload', 'Upload', static function () {
            echo 'UPLOAD_BODY';
        });
        $this->page->addTab('history', 'History', static function () {
            echo 'HISTORY_BODY';
        });

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        // Chrome
        $this->assertStringContainsString('wrap hyperpress hyperpress-options-wrap', $output);
        $this->assertStringContainsString('data-hyperpress-sticky-header', $output);
        $this->assertStringContainsString('hyperpress-layout__header-heading', $output);
        $this->assertStringContainsString('Test Page', $output);

        // Tabs nav (URL based)
        $this->assertStringContainsString('nav-tab-wrapper hyperpress-nav-tab-wrapper', $output);
        $this->assertStringContainsString('Upload', $output);
        $this->assertStringContainsString('History', $output);
        // First tab is active by default
        $this->assertStringContainsString('nav-tab-active', $output);

        // Notice catcher present (relocation target for the sticky-header JS)
        $this->assertStringContainsString('hyperpress-notice-catcher', $output);
        $this->assertStringContainsString('id="hyperpress-layout__notice-catcher"', $output);

        // Active tab content rendered, inactive is not
        $this->assertStringContainsString('UPLOAD_BODY', $output);
        $this->assertStringNotContainsString('HISTORY_BODY', $output);
    }

    public function testRenderPageRendersActiveTabContentFromUrl()
    {
        $this->page->addTab('upload', 'Upload', static function () {
            echo 'UPLOAD_BODY';
        });
        $this->page->addTab('history', 'History', static function () {
            echo 'HISTORY_BODY';
        });

        $_GET['tab'] = 'history';

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString('HISTORY_BODY', $output);
        $this->assertStringNotContainsString('UPLOAD_BODY', $output);
    }

    public function testRenderPageHasNoSettingsForm()
    {
        // AdminPage is a non-form host: no <form>, no settings_fields(),
        // no submit_button. This is the core difference from OptionsPage.
        $this->page->addTab('upload', 'Upload', static function () {
            echo 'body';
        });

        Functions\expect('settings_fields')->never();
        Functions\expect('do_settings_fields')->never();
        Functions\expect('submit_button')->never();

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<form', $output);
        $this->assertStringNotContainsString('options.php', $output);
        $this->assertStringNotContainsString('type="submit"', $output);
    }

    public function testRenderPageWithFooter()
    {
        $this->page->setFooterContent('<p>Custom footer</p>');
        $this->page->addTab('upload', 'Upload', static function () {});

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString('Custom footer', $output);
        $this->assertStringContainsString('hyperpress-options-footer', $output);
    }

    public function testRenderPageWithoutTabsEmitsHeaderAndCatcherOnly()
    {
        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        // Header + catcher still present
        $this->assertStringContainsString('hyperpress-layout__header-heading', $output);
        $this->assertStringContainsString('hyperpress-notice-catcher', $output);
        // No tab nav
        $this->assertStringNotContainsString('nav-tab-wrapper', $output);
    }

    public function testRenderPageNoticeCatcherIsWrappedInRegion()
    {
        // The catcher must sit inside .hyperpress-notice-region so the
        // notice-hiding inline selector (.wrap.hyperpress-options-wrap > .notice)
        // stops matching after the JS relocates a notice, letting it reveal.
        $this->page->addTab('upload', 'Upload', static function () {});

        ob_start();
        $this->page->renderPage();
        $output = ob_get_clean();

        $this->assertStringContainsString('hyperpress-notice-region', $output);
        $this->assertStringContainsString('wp-header-end', $output);
    }

    public function testEnqueueAssetsForSettingsPageHook()
    {
        // TemplateLoader reads HYPERPRESS_PLUGIN_URL first; define it (guarded,
        // same pattern as OptionsPageTest) so the enqueue actually runs. We use
        // HYPERPRESS_* rather than HYPERFIELDS_* so we don't collide with
        // LibraryBootstrapTest's assertion on HYPERFIELDS_PLUGIN_URL.
        if (!defined('HYPERPRESS_PLUGIN_URL')) {
            define('HYPERPRESS_PLUGIN_URL', 'http://example.com/plugin/');
        }
        if (!defined('HYPERPRESS_VERSION')) {
            define('HYPERPRESS_VERSION', '2.0.7');
        }

        Functions\when('is_admin')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn();
        Functions\when('wp_localize_script')->justReturn();
        Functions\when('wp_add_inline_style')->justReturn();

        Functions\expect('wp_enqueue_script')
            ->atLeast()->once()
            ->andReturn();

        $this->page->enqueueAssets('settings_page_test-page');
    }

    public function testEnqueueAssetsForSubmenuHook()
    {
        if (!defined('HYPERPRESS_PLUGIN_URL')) {
            define('HYPERPRESS_PLUGIN_URL', 'http://example.com/plugin/');
        }
        if (!defined('HYPERPRESS_VERSION')) {
            define('HYPERPRESS_VERSION', '2.0.7');
        }

        $this->page->setParentSlug('test-settings');

        Functions\when('is_admin')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn();
        Functions\when('wp_localize_script')->justReturn();
        Functions\when('wp_add_inline_style')->justReturn();

        Functions\expect('wp_enqueue_script')
            ->atLeast()->once()
            ->andReturn();

        $this->page->enqueueAssets('test-settings_page_test-page');
    }

    public function testEnqueueAssetsWrongPage()
    {
        // Must not touch any WP enqueue function on an unrelated hook suffix.
        Functions\when('wp_enqueue_script')->justReturn();
        Functions\when('wp_enqueue_style')->justReturn();
        $this->page->enqueueAssets('totally_unrelated_page');
        $this->assertTrue(true);
    }

    public function testFluentInterface()
    {
        $result = $this->page->setMenuTitle('Custom Title')
            ->setCapability('edit_posts')
            ->setParentSlug('custom-parent')
            ->setIconUrl('dashicon')
            ->setPosition(25)
            ->setFooterContent('<p>foo</p>')
            ->addTab('upload', 'Upload', static function () {});

        $this->assertSame($this->page, $result);
    }
}
