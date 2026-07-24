<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the class-shadowing safety net in
 * hyperfields_resolve_plugin_url() / hyperfields_is_class_shadowed().
 *
 * Background (OBA staging outage, 2026-07-24): the multi-instance version
 * election guarantees the newest *init* runs but cannot guarantee the newest
 * *class* is loaded. When a consumer bundles a stale HyperFields (< 1.4.1)
 * whose LibraryBootstrap lacks resolveContentUrl(), the elected-newest init
 * calling that method fatals. The guard detects the divergence, alarms loudly,
 * and falls back to plugins_url() instead of crashing.
 *
 * The LibraryBootstrap FQCN and the alarm callable are both injectable so the
 * branches are exercisable with lightweight stub classes and a capturing
 * closure — no process isolation, no error_log capture under the test harness.
 */
class ResolvePluginUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // helpers.php bails during Composer's early files-autoload (Pest loads
        // the autoloader before tests/bootstrap.php defines ABSPATH/
        // HYPERFIELDS_TESTING_MODE), so its functions are never defined in the
        // test process. Force-include it now; the guard passes (constants are
        // defined) and every definition is idempotent via function_exists().
        if (!\function_exists('hyperfields_resolve_content_url')) {
            require __DIR__ . '/../../includes/helpers.php';
        }

        // Fallback path calls the WP plugins_url(); stub it so the test does
        // not depend on WordPress being loaded.
        Functions\when('plugins_url')->alias(static function ($path = '', $file = ''): string {
            return 'http://example.com/plugins/' . ltrim((string) $file, '/');
        });
    }

    /**
     * The shadow predicate is the alarm trigger. True ONLY when the class is
     * loaded but resolveContentUrl() is absent — the stale-copy shape.
     */
    public function testIsClassShadowedDetectsTheStaleShape(): void
    {
        $this->assertTrue(\hyperfields_is_class_shadowed(StaleBootstrap::class), 'Stale class (method absent) IS shadowed.');
        $this->assertFalse(\hyperfields_is_class_shadowed(FreshBootstrap::class), 'Fresh class (method present) is NOT shadowed.');
        $this->assertFalse(\hyperfields_is_class_shadowed('AClassThatDoesNotExist\Anywhere'), 'Missing class is NOT shadowed (normal fallback, not an alarm case).');
    }

    /**
     * Behavioral drift: the method exists but the loaded class is older than
     * 1.4.1 (the version that introduced the stable resolveContentUrl()
     * contract). The VERSION-constant check catches this where method_exists
     * alone would miss it — Opus's #1 point. A present-but-changed method is
     * still a shadow.
     */
    public function testIsClassShadowedDetectsVersionDrift(): void
    {
        $this->assertTrue(\hyperfields_is_class_shadowed(DriftedBootstrap::class), 'Class with the method but an old VERSION IS shadowed (drift).');
    }

    /**
     * A LibraryBootstrap that has resolveContentUrl() (the normal case)
     * delegates to it and never alarms.
     */
    public function testFreshClassDelegatesToResolveContentUrl(): void
    {
        $fired = false;
        $url = \hyperfields_resolve_plugin_url(
            '/lib/dir',
            '/path/bootstrap.php',
            '1.4.2',
            FreshBootstrap::class,
            static function () use (&$fired): void {
                $fired = true;
            },
        );

        $this->assertSame('fresh:///lib/dir', $url);
        $this->assertFalse($fired, 'No alarm when the method exists.');
    }

    /**
     * The shadow signature: class loaded but resolveContentUrl() absent. The
     * resolver must NOT call the method (would fatal), must fall back to
     * plugins_url(), and must hand a diagnosable message to the alarm callable.
     * Removing the guard makes this test fatal.
     */
    public function testStaleClassFallsBackAndAlarms(): void
    {
        $captured = '';
        $url = \hyperfields_resolve_plugin_url(
            '/lib/dir',
            '/path/bootstrap.php',
            '1.4.2',
            StaleBootstrap::class,
            static function (string $message) use (&$captured): void {
                $captured = $message;
            },
        );

        $this->assertSame(
            'http://example.com/plugins/path/bootstrap.php',
            $url,
            'Stale class must fall back to plugins_url(), never call the absent method.',
        );
        $this->assertNotSame('', $captured, 'Shadow alarm must fire.');
        $this->assertStringContainsString('class shadowing detected', $captured);
        $this->assertStringContainsString('v1.4.2', $captured, 'Alarm carries the elected version.');
        $this->assertStringContainsString('resolveContentUrl', $captured, 'Alarm names the missing method.');
    }

    /**
     * When no LibraryBootstrap is loaded at all, the resolver falls back
     * silently and never alarms. Normal path, not a shadow.
     */
    public function testMissingClassFallsBackSilently(): void
    {
        $fired = false;
        $url = \hyperfields_resolve_plugin_url(
            '/lib/dir',
            '/path/bootstrap.php',
            '1.4.2',
            'AClassThatDoesNotExist\Anywhere',
            static function () use (&$fired): void {
                $fired = true;
            },
        );

        $this->assertSame('http://example.com/plugins/path/bootstrap.php', $url);
        $this->assertFalse($fired, 'No alarm when the class is absent — this is not a shadow.');
    }

    /**
     * The shared procedural wrapper hyperfields_resolve_content_url() is the
     * chokepoint sibling libraries (HyperPress-Core, HyperBlocks) call. It must
     * be shadow-safe too: a fresh wrapper + stale LibraryBootstrap must return
     * '' (siblings bail) instead of fataling on the absent method. This was the
     * hole the 1.4.2 review caught — the guard lived only in the bootstrap path.
     */
    public function testWrapperDelegatesToFreshClass(): void
    {
        $fired = false;
        $url = \hyperfields_resolve_content_url(
            '/some/path',
            FreshBootstrap::class,
            static function () use (&$fired): void {
                $fired = true;
            },
        );

        $this->assertSame('fresh:///some/path', $url);
        $this->assertFalse($fired, 'No alarm when the method exists.');
    }

    public function testWrapperBailsOnStaleClassInsteadOfFataling(): void
    {
        $captured = '';
        $url = \hyperfields_resolve_content_url(
            '/some/path',
            StaleBootstrap::class,
            static function (string $message) use (&$captured): void {
                $captured = $message;
            },
        );

        $this->assertSame('', $url, 'Stale class via the wrapper must return empty (siblings bail), not fatal.');
        $this->assertNotSame('', $captured, 'Wrapper alarm must fire.');
        $this->assertStringContainsString('class shadowing detected', $captured);
    }
}

/**
 * Stub LibraryBootstrap that HAS resolveContentUrl() (the post-1.4.1 shape).
 */
class FreshBootstrap
{
    public static function resolveContentUrl(string $plugin_dir): string
    {
        return 'fresh://' . $plugin_dir;
    }
}

/**
 * Stub LibraryBootstrap that LACKS resolveContentUrl() — mimics a stale bundled
 * copy (< 1.4.1) shadowing the elected-newest init. The exact shape that caused
 * the OBA fatal.
 */
class StaleBootstrap
{
    // Intentionally no resolveContentUrl().
}

/**
 * Stub LibraryBootstrap that HAS resolveContentUrl() but stamps an OLD VERSION
 * (< 1.4.1). Mimics a present-but-behaviorally-changed method — the drift case
 * method_exists alone cannot detect. The version-aware predicate catches it.
 */
class DriftedBootstrap
{
    public const VERSION = '1.3.0';

    public static function resolveContentUrl(string $plugin_dir): string
    {
        return 'drifted://' . $plugin_dir;
    }
}
