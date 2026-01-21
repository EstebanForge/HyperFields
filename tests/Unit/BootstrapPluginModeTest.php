<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
class BootstrapPluginModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('has_action')->justReturn(false);
        Functions\when('esc_html__')->returnArg();
        Functions\when('get_file_data')->justReturn(['Version' => '1.2.3']);
        Functions\when('plugin_dir_path')->alias(static function (string $file): string {
            return rtrim(dirname($file), '/\\') . '/';
        });
        Functions\when('plugin_dir_url')->justReturn('http://example.com/wp-content/plugins/hyperfields/');
        Functions\when('plugin_basename')->justReturn('hyperfields/hyperfields.php');
        Functions\when('register_activation_hook')->justReturn(true);
        Functions\when('register_deactivation_hook')->justReturn(true);
        Functions\when('trailingslashit')->alias(static function (string $path): string {
            return rtrim($path, '/\\') . '/';
        });
        Functions\when('is_admin')->justReturn(false);
        Functions\when('do_action')->justReturn(null);

        if (!defined('HYPERFIELDS_TESTING_MODE')) {
            define('HYPERFIELDS_TESTING_MODE', true);
        }
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wordpress/');
        }

        require_once dirname(__DIR__, 3) . '/HyperFields/bootstrap.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testPluginModeDefinesConstantsAndUrls(): void
    {
        $plugin_file = dirname(__DIR__, 3) . '/HyperFields/hyperfields.php';

        $GLOBALS['hyperfields_api_candidates'] = [
            $plugin_file => [
                'version' => '1.2.3',
                'path' => $plugin_file,
                'init_function' => 'hyperfields_run_initialization_logic',
            ],
        ];

        \hyperfields_select_and_load_latest();

        $this->assertSame(plugin_dir_path($plugin_file), HYPERFIELDS_ABSPATH);
        $this->assertSame('http://example.com/wp-content/plugins/hyperfields/', HYPERFIELDS_PLUGIN_URL);
        $this->assertSame(HYPERFIELDS_PLUGIN_URL, HYPERPRESS_PLUGIN_URL);
        $this->assertSame('1.2.3', HYPERFIELDS_VERSION);
        $this->assertSame('1.2.3', HYPERPRESS_VERSION);
    }
}
}
