<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Abilities\AbilityRegistrar;
use HyperFields\Field;
use HyperFields\OptionsPage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the HyperFields Abilities registrar: registration structure,
 * per-page permission resolution, and the execute callbacks.
 */
final class AbilityRegistrarTest extends TestCase
{
    /** @var array<string, array> */
    private static array $registered_abilities = [];

    /** @var array<string, mixed> Simulated option storage. */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // The shared bootstrap stubs apply_filters as a global passthrough;
        // these tests need real filter behavior, so re-stub both sides with
        // a tiny priority-10 stack (Patchwork redefinition, restored on
        // Monkey\tearDown).
        $hooks = [];
        Functions\when('add_filter')->alias(function ($hook, $cb, $priority = 10) use (&$hooks): bool {
            $hooks[$hook][$priority][] = $cb;
            return true;
        });
        Functions\when('apply_filters')->alias(function ($hook, $value, ...$args) use (&$hooks) {
            foreach ($hooks[$hook][10] ?? [] as $cb) {
                $value = $cb($value, ...$args);
            }
            return $value;
        });

        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('is_email')->alias(static fn ($value) => filter_var((string) $value, FILTER_VALIDATE_EMAIL) ? $value : false);
        Functions\when('absint')->alias(static fn ($value) => abs((int) $value));
        Functions\when('sanitize_hex_color')->returnArg();
        Functions\when('add_action')->justReturn(null);
        Functions\when('doing_filter')->justReturn(false);

        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $this->options[$name] ?? $default;
        });
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('current_user_can')->alias(static fn ($cap) => $cap === 'manage_options');

        Functions\when('wp_register_ability_category')->alias(static function (string $slug, array $args = []): void {
            // Recorded via $registered_abilities assertions on abilities only.
        });
        Functions\when('wp_register_ability')->alias(function (string $name, array $args = []): void {
            self::$registered_abilities[$name] = $args;
        });

        self::$registered_abilities = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        self::$registered_abilities = [];
        parent::tearDown();
    }

    private function makePageWithField(): OptionsPage
    {
        $page = OptionsPage::make('Test Options', 'test-page');
        $page->addSection('general', 'General')->addField(
            Field::make('email', 'contact_email', 'Contact')->setDefault('default@example.com')
        );
        $page->register();

        return $page;
    }

    public function test_registers_three_abilities_with_contract_meta(): void
    {
        AbilityRegistrar::registerCategories();
        AbilityRegistrar::registerAbilities();

        $this->assertSame(
            [
                'hyperfields/list-option-pages',
                'hyperfields/get-option',
                'hyperfields/update-option',
            ],
            array_keys(self::$registered_abilities)
        );

        foreach (self::$registered_abilities as $name => $args) {
            $this->assertSame(AbilityRegistrar::CATEGORY, $args['category'], $name);
            $this->assertFalse($args['meta']['show_in_rest'], $name);
            $this->assertArrayNotHasKey('mcp', $args['meta'], $name);
            $this->assertFalse($args['meta']['annotations']['destructive'], $name);
            $this->assertTrue($args['meta']['annotations']['idempotent'], $name);
        }

        // Reads are readonly; the write is not.
        $this->assertTrue(self::$registered_abilities['hyperfields/list-option-pages']['meta']['annotations']['readonly']);
        $this->assertTrue(self::$registered_abilities['hyperfields/get-option']['meta']['annotations']['readonly']);
        $this->assertFalse(self::$registered_abilities['hyperfields/update-option']['meta']['annotations']['readonly']);

        // Reads/writes resolve capability per page; the inventory is
        // manage_options.
        $this->assertSame(
            [AbilityRegistrar::class, 'currentUserCanForPage'],
            self::$registered_abilities['hyperfields/get-option']['permission_callback']
        );
        $this->assertSame(
            [AbilityRegistrar::class, 'currentUserCanManageOptions'],
            self::$registered_abilities['hyperfields/list-option-pages']['permission_callback']
        );
    }

    public function test_ability_args_honor_exposure_filters(): void
    {
        add_filter('hyperfields/abilities/expose_rest', static fn (): bool => true);
        add_filter('hyperfields/abilities/mcp_public', static fn (): bool => true);

        $args = AbilityRegistrar::abilityArgs(['label' => 'Test']);

        $this->assertTrue($args['meta']['show_in_rest']);
        $this->assertTrue($args['meta']['mcp']['public']);
    }

    public function test_page_permission_resolves_capability_and_fails_closed(): void
    {
        $this->makePageWithField(); // capability defaults to manage_options

        $this->assertTrue(AbilityRegistrar::currentUserCanForPage(['page' => 'test-page']));
        $this->assertFalse(AbilityRegistrar::currentUserCanForPage(['page' => 'unknown-page']));
        $this->assertFalse(AbilityRegistrar::currentUserCanForPage(null));
    }

    public function test_list_option_pages_includes_fields_with_schemas(): void
    {
        $this->makePageWithField();

        $pages = AbilityRegistrar::executeListOptionPages();

        $this->assertCount(1, $pages);
        $this->assertSame('test-page', $pages[0]['page']);
        $this->assertSame('hyperpress_options', $pages[0]['option_group']);
        $this->assertSame('manage_options', $pages[0]['capability']);

        $this->assertCount(1, $pages[0]['fields']);
        $this->assertSame('contact_email', $pages[0]['fields'][0]['name']);
        $this->assertSame('email', $pages[0]['fields'][0]['type']);
        $this->assertSame('email', $pages[0]['fields'][0]['schema']['format']);
    }

    public function test_get_option_returns_stored_then_default(): void
    {
        $this->makePageWithField();

        $result = AbilityRegistrar::executeGetOption(['page' => 'test-page', 'field' => 'contact_email']);
        $this->assertSame(['value' => 'default@example.com'], $result);

        $this->options['hyperpress_options'] = ['contact_email' => 'stored@example.com'];
        $result = AbilityRegistrar::executeGetOption(['page' => 'test-page', 'field' => 'contact_email']);
        $this->assertSame(['value' => 'stored@example.com'], $result);
    }

    public function test_get_option_reports_unknown_page_and_field(): void
    {
        $this->makePageWithField();

        $this->assertInstanceOf(\WP_Error::class, AbilityRegistrar::executeGetOption(['page' => 'missing', 'field' => 'x']));
        $this->assertInstanceOf(\WP_Error::class, AbilityRegistrar::executeGetOption(['page' => 'test-page', 'field' => 'missing']));
    }

    public function test_update_option_writes_through_field_sanitization(): void
    {
        $this->makePageWithField();

        $result = AbilityRegistrar::executeUpdateOption([
            'page'  => 'test-page',
            'field' => 'contact_email',
            'value' => 'writer@example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('writer@example.com', $this->options['hyperpress_options']['contact_email']);
        $this->assertSame('default@example.com', $result['field_default']);
    }

    public function test_update_option_reports_unknown_page_and_field(): void
    {
        $this->makePageWithField();

        $this->assertInstanceOf(
            \WP_Error::class,
            AbilityRegistrar::executeUpdateOption(['page' => 'missing', 'field' => 'x', 'value' => 1])
        );
        $this->assertInstanceOf(
            \WP_Error::class,
            AbilityRegistrar::executeUpdateOption(['page' => 'test-page', 'field' => 'missing', 'value' => 1])
        );
    }

    public function test_update_option_fails_on_field_validation(): void
    {
        $page = OptionsPage::make('Test Options', 'test-page');
        $page->addSection('general', 'General')->addField(
            Field::make('text', 'locked_field', 'Locked')
                ->setDefault('safe')
                ->addArg('wps_validate', static fn (): bool => false)
        );
        $page->register();

        $result = AbilityRegistrar::executeUpdateOption([
            'page'  => 'test-page',
            'field' => 'locked_field',
            'value' => 'anything',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayNotHasKey('hyperpress_options', $this->options);
    }

    public function test_init_is_a_noop_without_the_abilities_api(): void
    {
        if (class_exists(\WP_Ability::class)) {
            $this->markTestSkipped('Abilities API is present in this environment.');
        }

        $this->assertNull(AbilityRegistrar::init());
    }
}
