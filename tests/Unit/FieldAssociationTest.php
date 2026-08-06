<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Characterization + regression tests for the association field template.
 *
 * The dropdown is bounded (posts_per_page 200) so a large post-type table
 * cannot exhaust memory. Three properties are pinned:
 *
 *  - testRendersOneOptionPerPostAndMarksTheSelectedValue: rendering survives
 *    (one option per post; the selected value is marked).
 *  - testGetPostsIsBoundedAndSkipsCacheWarming: the main query stays bounded
 *    with term/meta cache warming off.
 *  - testStoredSelectionBeyondWindowStillRenders: an already-stored selection
 *    whose post sorts beyond the 200-post window still renders, so it is not
 *    silently cleared on the next save. Fail-first guard for the post__in union.
 */
class FieldAssociationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<array<string, mixed>> */
    private array $getPostsCalls = [];

    /** @var list<object> */
    private array $allPosts;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();

        $this->getPostsCalls = [];
        // IDs 10/20/30 are the (simulated) 200-post window; 999 sorts beyond it.
        $this->allPosts = [
            (object) ['ID' => 10, 'post_title' => 'Apple'],
            (object) ['ID' => 20, 'post_title' => 'Banana'],
            (object) ['ID' => 30, 'post_title' => 'Cherry'],
            (object) ['ID' => 999, 'post_title' => 'Zucchini'],
        ];

        // Record every call. The main bounded query has no post__in; the union
        // query (for stored selections missing from the window) carries one.
        Functions\when('get_posts')->alias(function (array $args): array {
            $this->getPostsCalls[] = $args;

            if (isset($args['post__in']) && is_array($args['post__in'])) {
                $in = array_map('intval', $args['post__in']);

                return array_values(array_filter(
                    $this->allPosts,
                    static fn ($p): bool => in_array((int) $p->ID, $in, true)
                ));
            }

            // Main bounded query: simulate the 200-post window as the first
            // three posts (excludes the beyond-window post 999).
            return [$this->allPosts[0], $this->allPosts[1], $this->allPosts[2]];
        });

        // Faithful WP selected() semantics: compare (string)$selected ===
        // (string)$current and ECHO by default (the template uses a bare
        // statement, relying on the internal echo). A return-only stub would
        // emit nothing under test.
        Functions\when('selected')->alias(static function ($selected, $current = true, $echo = true): string {
            $result = ((string) $selected === (string) $current) ? " selected='selected'" : '';
            if ($echo) {
                echo $result;
            }

            return $echo ? '' : $result;
        });

        Functions\when('wp_json_encode')->alias(static function ($data): string {
            return json_encode($data) ?: '';
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Render the template with a given $field_data bag, returning its output.
     *
     * @param array<string, mixed> $fieldData
     */
    private function render(array $fieldData): string
    {
        $field_data = $fieldData;
        ob_start();
        require dirname(__DIR__, 2) . '/src/templates/field-association.php';

        return (string) ob_get_clean();
    }

    /**
     * One <option> per returned post; the selected value is marked selected.
     * Preserved behavior.
     */
    public function testRendersOneOptionPerPostAndMarksTheSelectedValue(): void
    {
        $html = $this->render([
            'type' => 'association',
            'name' => 'rel_field',
            'label' => 'Related',
            'value' => [20],
            'options' => ['post_type' => 'post', 'multiple' => false],
        ]);

        $this->assertStringContainsString('value="10"', $html);
        $this->assertStringContainsString('Apple', $html);
        $this->assertStringContainsString('value="20"', $html);
        $this->assertStringContainsString('Banana', $html);
        $this->assertStringContainsString('value="30"', $html);
        $this->assertStringContainsString('Cherry', $html);
        $this->assertStringContainsString("selected='selected'", $html);
        $this->assertStringNotContainsString('value="999"', $html);
    }

    /**
     * The main query must be bounded (not -1) and must not warm term/meta
     * caches. Asserted against the FIRST get_posts call (the main one).
     */
    public function testGetPostsIsBoundedAndSkipsCacheWarming(): void
    {
        $this->render([
            'type' => 'association',
            'name' => 'rel_field',
            'options' => ['post_type' => 'post'],
        ]);

        $this->assertNotEmpty($this->getPostsCalls, 'get_posts was called');
        $args = $this->getPostsCalls[0];
        $this->assertNotSame(-1, $args['posts_per_page'] ?? null, 'posts_per_page must be bounded, not -1');
        $this->assertSame(false, $args['update_post_term_cache'] ?? null, 'term cache warming must be off');
        $this->assertSame(false, $args['update_post_meta_cache'] ?? null, 'meta cache warming must be off');
    }

    /**
     * A stored selection whose post sorts beyond the bounded window must still
     * render, so the browser does not submit the empty default option and clear
     * the association on save. Fails on the bounded-only code (no union); passes
     * once the template unions the stored value into the query.
     */
    public function testStoredSelectionBeyondWindowStillRenders(): void
    {
        $html = $this->render([
            'type' => 'association',
            'name' => 'rel_field',
            'value' => [999],
            'options' => ['post_type' => 'post'],
        ]);

        // A second get_posts call must fetch the missing stored selection by ID.
        $unionCalls = array_filter($this->getPostsCalls, static fn ($a): bool => isset($a['post__in']));
        $this->assertNotEmpty($unionCalls, 'a post__in union query fetches out-of-window selections');
        $this->assertStringContainsString('value="999"', $html, 'the stored selection renders');
        $this->assertStringContainsString('Zucchini', $html);
    }
}
