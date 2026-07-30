<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\CacheInvalidator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

// Minimal wpdb stub so instanceof checks in purgeDatabaseTransients()
// resolve. The real wpdb class is never loaded in unit tests; without this
// the guard short-circuits and the SQL branch could never be exercised.
if (!class_exists('wpdb')) {
    class wpdb
    {
        public $options = 'wp_options';

        public $sitemeta = 'wp_sitemeta';

        // Declared so Mockery recognizes them when mocking this concrete class
        // (strict mode rejects expectations on undeclared methods).
        public function esc_like($text)
        {
            return $text;
        }

        public function prepare($query, ...$args)
        {
            return $query;
        }

        public function query($sql)
        {
            return true;
        }
    }
}

class CacheInvalidatorTest extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> Per-tag apply_filters overrides for the current test. */
    private array $filterOverrides = [];

    /** @var bool Return value of wp_using_ext_object_cache() for the current test. */
    private bool $usingExtObjectCache = false;

    /** @var bool Return value of wp_cache_supports('flush_group'). */
    private bool $supportsFlushGroup = true;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();

        // Deterministic apply_filters: return the registered override for a
        // tag, otherwise the value (2nd argument). Replaces the bootstrap's
        // returnArg() stub so filter gating is testable.
        $overrides = &$this->filterOverrides;
        Functions\when('apply_filters')->alias(
            static function ($tag, $value = null) use (&$overrides) {
                return array_key_exists($tag, $overrides) ? $overrides[$tag] : $value;
            }
        );

        // Backend-aware stubs driven by test properties so each test can dial
        // in the cache topology it is exercising.
        $using = &$this->usingExtObjectCache;
        Functions\when('wp_using_ext_object_cache')->alias(static function () use (&$using) {
            return $using;
        });

        $supports = &$this->supportsFlushGroup;
        Functions\when('wp_cache_supports')->alias(static function ($feature) use (&$supports) {
            return $feature === 'flush_group' ? $supports : false;
        });

        Functions\when('is_multisite')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_init_registers_all_save_actions(): void
    {
        CacheInvalidator::init();

        $expected = [
            'hyperfields/options_page/after_save',
            'hyperfields/settings/after_save',
            'hyperfields/post_meta_container_saved',
            'hyperfields/term_meta_container_saved',
            'hyperfields/user_meta_container_saved',
            'hyperfields/import/after',
        ];

        foreach ($expected as $action) {
            $priority = Monkey\Actions\has($action, [CacheInvalidator::class, 'onSave']);
            $this->assertNotFalse(
                $priority,
                "init() must register onSave on {$action}"
            );
        }
    }

    public function test_is_enabled_defaults_to_true(): void
    {
        $this->assertTrue(CacheInvalidator::isEnabled());
    }

    public function test_is_enabled_respects_master_filter(): void
    {
        $this->filterOverrides['hyperfields/cache/auto_invalidate'] = false;

        $this->assertFalse(CacheInvalidator::isEnabled());
    }

    public function test_on_save_is_a_noop_when_disabled(): void
    {
        $this->filterOverrides['hyperfields/cache/auto_invalidate'] = false;

        // None of the destructive branches may run when the master switch is off.
        Functions\expect('wp_cache_flush_group')->never();
        Functions\expect('wp_cache_flush')->never();

        CacheInvalidator::onSave();
    }

    /**
     * External object cache + group-flush support: clear only the transient
     * groups surgically. No DB write, no full flush.
     */
    public function test_ext_cache_uses_group_flush_and_skips_db_and_full_flush(): void
    {
        $this->usingExtObjectCache = true;
        $this->supportsFlushGroup = true;

        Functions\expect('wp_cache_flush_group')->once()->with('transient');
        Functions\expect('wp_cache_flush_group')->once()->with('site-transient');
        Functions\expect('wp_cache_flush')->never();

        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->shouldReceive('query')->never();
        $GLOBALS['wpdb'] = $wpdb;

        CacheInvalidator::flush();
    }

    /**
     * External object cache whose backend does NOT advertise group-flush
     * support (some Memcached drop-ins). Default behavior leaves transients
     * alone rather than doing a full, expensive flush.
     */
    public function test_ext_cache_without_group_support_does_nothing_by_default(): void
    {
        $this->usingExtObjectCache = true;
        $this->supportsFlushGroup = false;

        Functions\expect('wp_cache_flush_group')->never();
        Functions\expect('wp_cache_flush')->never();

        CacheInvalidator::flush();
    }

    /**
     * The full wp_cache_flush() opt-in is off by default and must not fire
     * even when an external object cache is present.
     */
    public function test_full_object_cache_flush_defaults_off(): void
    {
        $this->usingExtObjectCache = true;
        $this->supportsFlushGroup = true;

        // The transient layer still runs (group flush) by default; only the
        // full flush must be off.
        Functions\when('wp_cache_flush_group')->justReturn();
        Functions\expect('wp_cache_flush')->never();

        CacheInvalidator::flush();
    }

    /**
     * Opting into the full flush still requires an external object cache;
     * on a DB-backed install there is nothing to flush.
     */
    public function test_full_flush_opt_in_skipped_without_external_cache(): void
    {
        $this->usingExtObjectCache = false;
        $this->filterOverrides['hyperfields/cache/flush_object_cache'] = true;

        Functions\expect('wp_cache_flush')->never();

        CacheInvalidator::flush();
    }

    public function test_full_flush_opt_in_runs_with_external_cache(): void
    {
        $this->usingExtObjectCache = true;
        $this->filterOverrides['hyperfields/cache/flush_object_cache'] = true;

        // Group flush still runs (transients layer on), then the opt-in full flush.
        Functions\expect('wp_cache_flush_group')->times(2);
        Functions\expect('wp_cache_flush')->once();

        CacheInvalidator::flush();
    }

    /**
     * No external object cache: transients live in wp_options and are purged
     * by a direct SQL DELETE.
     */
    public function test_db_backend_purges_options_rows(): void
    {
        $this->usingExtObjectCache = false;

        Functions\expect('wp_cache_flush_group')->never();

        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->with('_transient_')->andReturn('_transient_');
        $wpdb->shouldReceive('esc_like')->with('_site_transient_')->andReturn('_site_transient_');
        $wpdb->shouldReceive('prepare')->atLeast()->once()->andReturn('PURGE_SQL');
        $wpdb->shouldReceive('query')->with('PURGE_SQL')->once();
        $GLOBALS['wpdb'] = $wpdb;

        CacheInvalidator::flush();
    }

    public function test_flush_respects_transients_filter(): void
    {
        $this->usingExtObjectCache = false;
        $this->filterOverrides['hyperfields/cache/flush_transients'] = false;

        Functions\expect('wp_cache_flush_group')->never();

        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->shouldReceive('prepare')->never();
        $wpdb->shouldReceive('query')->never();
        $GLOBALS['wpdb'] = $wpdb;

        CacheInvalidator::flush();
    }

    public function test_flush_does_not_fatal_without_wpdb(): void
    {
        $this->usingExtObjectCache = false;
        unset($GLOBALS['wpdb']);

        // purgeDatabaseTransients() must bail cleanly when no wpdb is present.
        $this->assertNull(CacheInvalidator::flush());
    }
}
