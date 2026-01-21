<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\LibraryBootstrap;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
class LibraryBootstrapTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('trailingslashit')->alias(static function (string $path): string {
            return rtrim($path, '/\\') . '/';
        });

        Functions\when('plugin_dir_path')->alias(static function (string $file): string {
            return rtrim(dirname($file), '/\\') . '/';
        });

        Functions\when('plugins_url')->alias(static function (string $path = '', string $plugin = ''): string {
            $base = 'http://example.com/wp-content/plugins/';
            $plugin_dir = trim(basename(dirname($plugin)), '/');
            $url = rtrim($base, '/') . '/' . $plugin_dir;
            if ($path !== '') {
                $url .= '/' . ltrim($path, '/');
            }

            return $url;
        });

        Functions\when('add_action')->justReturn(true);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('do_action')->justReturn(null);

        // Only mock TemplateLoader if it hasn't been loaded yet by composer autoloader.
        if (!class_exists('HyperFields\TemplateLoader', false)) {
            \Mockery::mock('alias:HyperFields\TemplateLoader')
                ->shouldReceive('init')
                ->andReturnNull();
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testLibraryBootstrapDefinesConstants(): void
    {
        // Skip if constants are already defined by main bootstrap process.
        // This happens when @runInSeparateProcess doesn't fully isolate.
        if (defined('HYPERFIELDS_INSTANCE_LOADED')) {
            $this->markTestSkipped('HyperFields already initialized; cannot test LibraryBootstrap in isolation.');
        }

        $plugin_file = '/var/www/wp-content/plugins/host-plugin/host-plugin.php';
        $base_dir = '/var/www/wp-content/plugins/host-plugin/vendor/estebanforge/hyperfields/';
        $version = '9.9.9';

        LibraryBootstrap::init([
            'plugin_file' => $plugin_file,
            'base_dir' => $base_dir,
            'version' => $version,
        ]);

        $this->assertSame($base_dir, HYPERFIELDS_ABSPATH);
        $this->assertSame($plugin_file, HYPERFIELDS_PLUGIN_FILE);
        $this->assertSame(
            'http://example.com/wp-content/plugins/host-plugin/vendor/estebanforge/hyperfields/',
            HYPERFIELDS_PLUGIN_URL
        );
        $this->assertSame(HYPERFIELDS_PLUGIN_URL, HYPERPRESS_PLUGIN_URL);
        $this->assertSame($version, HYPERFIELDS_VERSION);
        $this->assertSame($version, HYPERPRESS_VERSION);
    }
}
