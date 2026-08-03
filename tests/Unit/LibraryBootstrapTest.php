<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Config;
use HyperFields\LibraryBootstrap;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * LibraryBootstrap tests must run in a child process: init() mutates Config
 * static state and defines the HYPERPRESS fallback constant, which must be
 * fresh. Config static properties reset per process, and the HYPERPRESS
 * constant cannot be undefined once set.
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
    public function testLibraryBootstrapPopulatesConfig(): void
    {
        // In a truly isolated child process the plugin bootstrap has not run,
        // so Config must not be initialized and the HYPERPRESS fallback
        // constants must be undefined. If a future change causes the autoload
        // chain to fire init() early, surface it loudly instead of skipping.
        if (
            Config::isInitialized()
            || defined('HYPERPRESS_PLUGIN_URL')
            || defined('HYPERPRESS_VERSION')
        ) {
            $this->fail('Config already initialized or HYPERPRESS constants already defined in isolated process; ' .
                'LibraryBootstrap::init() cannot be tested fresh. Check the autoload chain.');
        }

        $plugin_file = WP_PLUGIN_DIR . '/host-plugin/host-plugin.php';
        $base_dir = WP_PLUGIN_DIR . '/host-plugin/vendor/estebanforge/hyperfields/';

        LibraryBootstrap::init([
            'plugin_file' => $plugin_file,
            'base_dir' => $base_dir,
        ]);

        $this->assertTrue(Config::isInitialized());
        $this->assertSame($base_dir, Config::$abspath);
        $this->assertSame($plugin_file, Config::$pluginFile);
        $this->assertSame(
            WP_PLUGIN_URL . '/host-plugin/vendor/estebanforge/hyperfields/',
            Config::$pluginUrl
        );
        // VERSION is now a class constant (single source of truth), independent
        // of the version argument passed to init().
        $this->assertSame('1.5.0', Config::VERSION);
        // HyperFields defines NO HYPERPRESS_* constants: those are owned by
        // HyperPress-Core (no cross-plugin shared state), which resolves both
        // HYPERPRESS_VERSION and HYPERPRESS_PLUGIN_URL from its own bootstrap.
        // An earlier HyperFields version defined HYPERPRESS_VERSION and copied
        // HYPERFIELDS_PLUGIN_URL verbatim, silently propagating a broken
        // (404ing) URL into HyperPress-Core's frontend asset enqueue.
        $this->assertFalse(
            defined('HYPERPRESS_VERSION'),
            'HyperFields must not define HYPERPRESS_VERSION; HyperPress-Core owns it.'
        );
        $this->assertFalse(
            defined('HYPERPRESS_PLUGIN_URL'),
            'HyperFields must not define HYPERPRESS_PLUGIN_URL; HyperPress-Core owns it and resolves it from its own base directory.'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInitBailsWhenElectionConstantAlreadyDefined(): void
    {
        // Simulate a prior copy having claimed the first-to-boot election guard.
        define('HyperFields\\LOADED', '/prior-copy/src');

        LibraryBootstrap::init([]);

        $this->assertFalse(
            Config::isInitialized(),
            'init() must bail without initializing when the HyperFields\\LOADED election constant is already set.'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInitDefersWhenNotWebReachable(): void
    {
        // Simulate a Bedrock-style root composer vendor: outside every WP
        // content root, with no explicit plugin_url override. init() must
        // defer without claiming the namespace identity, so a web-reachable
        // copy (e.g. bundled inside a plugin under wp-content) can still win.
        $base_dir = sys_get_temp_dir() . '/bedrock-app/vendor/estebanforge/hyperfields/';

        LibraryBootstrap::init([
            'base_dir' => $base_dir,
        ]);

        $this->assertFalse(
            Config::isInitialized(),
            'init() must defer (not initialize Config) when the copy is not under a web-reachable WP content root.'
        );
        $this->assertFalse(
            defined('HyperFields\\LOADED'),
            'A non-web-reachable copy must not define the LOADED election guard; a web-reachable copy must be free to claim it.'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInitProceedsWithExplicitPluginUrlOverride(): void
    {
        // A consumer that knows its own URL can force a copy the resolver
        // cannot infer. Even with a non-web-reachable base_dir, an explicit
        // plugin_url must bypass the deferral and claim the identity.
        $base_dir = sys_get_temp_dir() . '/bedrock-app/vendor/estebanforge/hyperfields/';
        $override = 'http://example.com/wp-content/plugins/host-plugin/vendor/estebanforge/hyperfields/';

        LibraryBootstrap::init([
            'base_dir' => $base_dir,
            'plugin_url' => $override,
        ]);

        $this->assertTrue(Config::isInitialized());
        $this->assertSame($override, Config::$pluginUrl);
    }
}
