<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use HyperFields\LibraryBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LibraryBootstrap::resolveContentUrl() and its procedural
 * wrapper hyperfields_resolve_content_url().
 *
 * Pins the fix for admin/options-page assets and field JS silently failing
 * to load when HyperFields is vendored into a non-plugin directory. WordPress'
 * plugins_url($path, $file) resolves correctly only when $file sits directly
 * under WP_PLUGIN_DIR: it calls plugin_basename(), which strips that one
 * prefix and nothing else. Vendored copies that live elsewhere — most notably
 * a Bedrock application's root composer vendor (public_html/src/vendor),
 * outside both WP_PLUGIN_DIR and the web document root — produce a URL like
 * https://host/app/plugins/home/.../src/vendor/... that 404s. Assets
 * enqueued from that URL never load (broken HyperFields options pages,
 * missing multiselect-enhanced.js), and for the sibling HyperBlocks library
 * the editor script fails to register blocks client-side so fluent blocks
 * vanish from the Gutenberg inserter while still rendering on the front end.
 */
class ResolveContentUrlTest extends TestCase
{
    /**
     * A nested vendor path inside WP_PLUGIN_DIR — the consumer plugin's own
     * bundled copy — resolves to the correct public URL.
     */
    public function testResolvesNestedPluginVendorPath(): void
    {
        $file = WP_PLUGIN_DIR . '/host-plugin/vendor/estebanforge/hyperfields/bootstrap.php';

        $this->assertSame(
            WP_PLUGIN_URL . '/host-plugin/vendor/estebanforge/hyperfields/bootstrap.php',
            LibraryBootstrap::resolveContentUrl($file)
        );
    }

    /**
     * The procedural wrapper hyperfields_resolve_content_url() is defined in
     * includes/helpers.php and delegates to the static method. It is exercised
     * by HyperBlocks/HyperPress in production (where helpers.php loads at
     * autoload time); the static method itself is covered above.
     */

    /**
     * A path that equals a content root exactly returns the root URL with no
     * trailing slash and no doubled segment.
     */
    public function testResolvesExactRootMatchWithoutTrailingSlash(): void
    {
        $this->assertSame(WP_PLUGIN_URL, LibraryBootstrap::resolveContentUrl(WP_PLUGIN_DIR));
    }

    /**
     * Prefix matching is anchored to a directory boundary: a sibling whose
     * name merely shares a prefix (e.g. '/wp-content-x') must not match
     * WP_CONTENT_DIR ('/wp-content').
     */
    public function testDoesNotMatchOnSharedPrefixWithoutDirectoryBoundary(): void
    {
        $sibling = dirname(WP_CONTENT_DIR) . '/wp-content-evil/inside.php';

        $this->assertSame('', LibraryBootstrap::resolveContentUrl($sibling));
    }

    /**
     * A Bedrock-style root composer vendor lives outside WP_PLUGIN_DIR and the
     * web document root, so it is not HTTP-reachable. The resolver returns ''
     * so callers can bail and log instead of enqueuing a 404ing URL.
     */
    public function testReturnsEmptyForPathOutsideWebAccessibleRoots(): void
    {
        // app root: sibling of WP_CONTENT_DIR, mimicking public_html/src vs
        // public_html/src/web/app. Not under any WP_*_DIR candidate.
        $appRoot = dirname(dirname(dirname(WP_CONTENT_DIR))) . '/src/vendor/estebanforge/hyperfields/bootstrap.php';

        $this->assertStringNotContainsStringIgnoringCase(WP_PLUGIN_DIR, $appRoot);
        $this->assertSame('', LibraryBootstrap::resolveContentUrl($appRoot));
    }

    /**
     * Backslashes (Windows-style paths) are normalized before matching, so the
     * resolver works cross-platform.
     */
    public function testNormalizesBackslashesBeforeMatching(): void
    {
        $file = str_replace('/', '\\', WP_PLUGIN_DIR . '/acme/vendor/hyperfields/bootstrap.php');

        $this->assertSame(
            WP_PLUGIN_URL . '/acme/vendor/hyperfields/bootstrap.php',
            LibraryBootstrap::resolveContentUrl($file)
        );
    }
}
