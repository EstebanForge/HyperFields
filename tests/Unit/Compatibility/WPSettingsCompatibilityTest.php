<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit\Compatibility;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Compatibility\WPSettingsCompatibility;
use HyperFields\OptionsPage;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class WPSettingsCompatibilityTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value): mixed {
            return $value;
        });
        Functions\when('sanitize_key')->alias(static function (string $value): string {
            $value = strtolower($value);
            $value = preg_replace('/[^a-z0-9_]/', '_', $value);

            return $value ?: '';
        });
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('doing_filter')->justReturn(false);
        Functions\when('register_setting')->justReturn(true);
        Functions\when('add_settings_section')->justReturn(true);
        Functions\when('add_settings_field')->justReturn(true);
        Functions\when('add_submenu_page')->justReturn('hook');
        Functions\when('add_menu_page')->justReturn('hook');
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('add_query_arg')->justReturn('http://example.com/page');
        Functions\when('settings_fields')->justReturn('');
        Functions\when('do_settings_fields')->justReturn('');
        Functions\when('submit_button')->justReturn('');
        Functions\when('get_the_ID')->justReturn(0);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testRegisterBuildsOptionsPageWithCustomRenderCallback(): void
    {
        $config = [
            'title' => 'Compat Settings',
            'slug' => 'compat-settings',
            'option_name' => 'compat_options',
            'tabs' => [
                [
                    'key' => 'general',
                    'label' => 'General',
                    'callback' => static function ($tab): void {
                        $section = $tab->add_section('Main', ['id' => 'main']);
                        $section->add_option('text', [
                            'name' => 'site_name',
                            'label' => 'Site Name',
                        ]);
                        $section->add_option('custom', [
                            'name' => 'custom_markup',
                            'label' => 'Custom Markup',
                            'render' => static function (): string {
                                return '<div>ok</div>';
                            },
                        ]);
                    },
                ],
            ],
        ];

        $page = WPSettingsCompatibility::register($config);

        $this->assertInstanceOf(OptionsPage::class, $page);
    }

    public function testLifecycleHooksUseWordPressUpdatedOptionArgumentOrder(): void
    {
        $capturedUpdatedOptionCallback = null;
        $triggeredActions = [];

        Functions\when('add_action')->alias(
            static function (
                string $hook,
                callable $callback,
                int $priority = 10,
                int $acceptedArgs = 1
            ) use (&$capturedUpdatedOptionCallback): bool {
                if ($hook === 'updated_option_compat_options_lifecycle') {
                    $capturedUpdatedOptionCallback = $callback;
                }

                return true;
            }
        );

        Functions\when('do_action')->alias(static function (...$args) use (&$triggeredActions): void {
            $triggeredActions[] = $args;
        });

        WPSettingsCompatibility::register([
            'title' => 'Compat Settings',
            'slug' => 'compat-settings',
            'option_name' => 'compat_options_lifecycle',
            'hook_prefix' => 'compat_settings',
            'tabs' => [
                [
                    'key' => 'general',
                    'label' => 'General',
                    'callback' => static function ($tab): void {
                        $tab->add_section('Main', ['id' => 'main'])
                            ->add_option('text', ['name' => 'site_name', 'label' => 'Site Name']);
                    },
                ],
            ],
        ]);

        $this->assertIsCallable($capturedUpdatedOptionCallback);

        call_user_func(
            $capturedUpdatedOptionCallback,
            ['site_name' => 'old'],
            ['site_name' => 'new'],
            'compat_options_lifecycle'
        );

        $after = array_values(array_filter(
            $triggeredActions,
            static fn (array $args): bool => isset($args[0]) && $args[0] === 'hyperfields/settings/after_save'
        ));
        $prefixedAfter = array_values(array_filter(
            $triggeredActions,
            static fn (array $args): bool => isset($args[0]) && $args[0] === 'compat_settings_after_save'
        ));

        $this->assertCount(1, $after);
        $this->assertCount(1, $prefixedAfter);
        $this->assertSame(['site_name' => 'new'], $after[0][1]);
        $this->assertSame(['site_name' => 'old'], $after[0][2]);
        $this->assertSame('compat_options_lifecycle', $after[0][3]);
    }
}
