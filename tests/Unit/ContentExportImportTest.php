<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\ContentExportImport;

class ContentExportImportTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubTranslationFunctions();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_title')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_site_url')->justReturn('https://example.test');
        Functions\when('maybe_unserialize')->returnArg();
        Functions\when('wp_json_encode')->alias(function ($data, $flags = 0) {
            return json_encode($data, $flags);
        });
        Functions\when('get_post')->justReturn(null);
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\when('get_posts')->justReturn([]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testExportPostsBasic(): void
    {
        $post = (object) [
            'ID' => 123,
            'post_type' => 'page',
            'post_name' => 'about',
            'post_title' => 'About',
            'post_status' => 'publish',
            'post_content' => 'Body',
            'post_excerpt' => 'Excerpt',
            'menu_order' => 0,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_parent' => 0,
        ];

        Functions\when('get_posts')->justReturn([$post]);
        Functions\when('get_post_meta')->justReturn([
            'public_meta' => ['value'],
            '_edit_lock' => ['12345'],
        ]);

        $json = ContentExportImport::exportPosts(['page']);
        $payload = json_decode($json, true);

        $this->assertIsArray($payload);
        $this->assertSame('hyperfields_content_export', $payload['type']);
        $this->assertSame('posts', $payload['scope']);
        $this->assertCount(1, $payload['content']['posts']);
        $this->assertSame('page', $payload['content']['posts'][0]['post_type']);
        $this->assertSame('about', $payload['content']['posts'][0]['slug']);
        $this->assertArrayHasKey('public_meta', $payload['content']['posts'][0]['meta']);
        $this->assertArrayNotHasKey('_edit_lock', $payload['content']['posts'][0]['meta']);
    }

    public function testImportPostsDryRunCreate(): void
    {
        $json = (string) json_encode([
            'version' => '1.0',
            'type' => 'hyperfields_content_export',
            'scope' => 'posts',
            'content' => [
                'posts' => [
                    [
                        'post_type' => 'page',
                        'slug' => 'new-page',
                        'title' => 'New Page',
                        'status' => 'publish',
                    ],
                ],
            ],
        ]);

        $result = ContentExportImport::importPosts($json, ['dry_run' => true]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['stats']['created']);
        $this->assertSame('create', $result['actions'][0]['action']);
        $this->assertTrue($result['actions'][0]['dry_run']);
    }
}
