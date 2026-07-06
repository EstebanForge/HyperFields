<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\LibraryBootstrap;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * LibraryBootstrap tests must run in a child process: the bootstrap asserts
 * that init() defines the HYPERFIELDS and HYPERPRESS constants fresh, which is
 * impossible once any other test or the plugin bootstrap has defined them.
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLibraryBootstrapDefinesConstants(): void
    {
        // In a truly isolated child process the plugin bootstrap has not run,
        // so the HYPERFIELDS and HYPERPRESS constants should be undefined here.
        // If a future change causes the bootstrap to pre-define them (e.g. an
        // autoloaded file firing init()), surface it loudly instead of skipping.
        if (
            defined('HYPERFIELDS_INSTANCE_LOADED')
            || defined('HYPERFIELDS_PLUGIN_URL')
            || defined('HYPERPRESS_PLUGIN_URL')
            || defined('HYPERPRESS_VERSION')
        ) {
            $this->fail('HYPERFIELDS or HYPERPRESS constants already defined in isolated process; ' .
                'LibraryBootstrap::init() cannot be tested fresh. Check the autoload chain.');
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
        $this->assertSame($version, HYPERFIELDS_VERSION);
        // LibraryBootstrap provides fallback HYPERPRESS_* constants when used standalone.
        $this->assertTrue(defined('HYPERPRESS_VERSION'));
        $this->assertTrue(defined('HYPERPRESS_PLUGIN_URL'));
        $this->assertSame($version, HYPERPRESS_VERSION);
        $this->assertSame(HYPERFIELDS_PLUGIN_URL, HYPERPRESS_PLUGIN_URL);
    }
}
