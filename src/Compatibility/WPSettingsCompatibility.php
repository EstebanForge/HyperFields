<?php

declare(strict_types=1);

namespace HyperFields\Compatibility;

use HyperFields\CustomField;
use HyperFields\Field;
use HyperFields\OptionsPage;
use WP_Error;

final class WPSettingsCompatibility
{
    /**
     * @var array<string, bool>
     */
    private static array $registered_lifecycle = [];

    public static function register(array $config): OptionsPage
    {
        $title = (string) ($config['title'] ?? $config['page_title'] ?? '');
        $slug = (string) ($config['slug'] ?? $config['menu_slug'] ?? '');
        if ($title === '' || $slug === '') {
            throw new \InvalidArgumentException('Settings compatibility config requires title and slug.');
        }

        $prefix = isset($config['prefix']) && is_string($config['prefix']) ? $config['prefix'] : '';
        $page = OptionsPage::make($title, $slug, $prefix);

        if (isset($config['menu_title']) && is_string($config['menu_title'])) {
            $page->setMenuTitle($config['menu_title']);
        }
        if (isset($config['parent_slug']) && is_string($config['parent_slug'])) {
            $page->setParentSlug($config['parent_slug']);
        }
        if (isset($config['capability']) && is_string($config['capability'])) {
            $page->setCapability($config['capability']);
        }
        if (isset($config['option_name']) && is_string($config['option_name'])) {
            $page->setOptionName($config['option_name']);
        }
        if (isset($config['footer_content']) && is_string($config['footer_content'])) {
            $page->setFooterContent($config['footer_content']);
        }

        $hook_prefix = self::resolveHookPrefix($config);
        $tabs = self::resolveTabs($config);
        $tabs = apply_filters('hyperfields/settings/tabs', $tabs, $config, $page);
        $tabs = apply_filters($hook_prefix . '_tabs', $tabs, $config, $page);
        $tabs = self::sortTabs($tabs);

        foreach ($tabs as $tab) {
            $key = (string) ($tab['key'] ?? '');
            $label = (string) ($tab['label'] ?? $tab['title'] ?? $key);
            if ($key === '') {
                continue;
            }

            $tab_proxy = new TabProxy($key, $label);
            if (isset($tab['callback']) && is_callable($tab['callback'])) {
                call_user_func($tab['callback'], $tab_proxy);
            }

            $tab_proxy = apply_filters('hyperfields/settings/tab/' . $key, $tab_proxy, $tab, $config, $page);
            $tab_proxy = apply_filters($hook_prefix . '_tab_' . $key, $tab_proxy, $tab, $config, $page);

            foreach ($tab_proxy->getSections() as $section_proxy) {
                $description = '';
                $section_args = $section_proxy->getArgs();
                if (isset($section_args['description']) && is_string($section_args['description'])) {
                    $description = $section_args['description'];
                }

                $section = $page->addSection($section_proxy->getId(), $section_proxy->getTitle(), $description);
                self::attachSectionOptions($section_proxy, $section);
            }
        }

        $page = apply_filters('hyperfields/settings/extend', $page, $config);
        $page = apply_filters($hook_prefix . '_extend', $page, $config);

        self::registerLifecycleHooks($page, $config, $hook_prefix);
        $page->register();

        return $page;
    }

    private static function resolveHookPrefix(array $config): string
    {
        $prefix = isset($config['hook_prefix']) && is_string($config['hook_prefix'])
            ? $config['hook_prefix']
            : 'hyperfields_settings';

        return preg_replace('/[^a-z0-9_]/', '_', strtolower($prefix)) ?: 'hyperfields_settings';
    }

    private static function resolveTabs(array $config): array
    {
        $tabs = $config['tabs'] ?? [];
        if (!is_array($tabs)) {
            return [];
        }

        $normalized = [];
        foreach ($tabs as $priority => $tab) {
            if (!is_array($tab)) {
                continue;
            }
            if (!isset($tab['priority']) && is_int($priority)) {
                $tab['priority'] = $priority;
            }
            $normalized[] = $tab;
        }

        return $normalized;
    }

    private static function sortTabs(array $tabs): array
    {
        usort($tabs, static function (array $left, array $right): int {
            $left_priority = isset($left['priority']) ? (int) $left['priority'] : 9999;
            $right_priority = isset($right['priority']) ? (int) $right['priority'] : 9999;

            return $left_priority <=> $right_priority;
        });

        return $tabs;
    }

    private static function attachSectionOptions(SectionProxy $section_proxy, \HyperFields\OptionsSection $section): void
    {
        $index = 0;
        foreach ($section_proxy->getOptions() as $option) {
            $type = isset($option['type']) ? (string) $option['type'] : 'text';
            $args = isset($option['args']) && is_array($option['args']) ? $option['args'] : [];
            $field = self::buildFieldFromOption($type, $args, $section_proxy, $index);
            $index++;

            if ($field) {
                $section->addField($field);
            }
        }
    }

    private static function buildFieldFromOption(
        string $type,
        array $args,
        SectionProxy $section_proxy,
        int $index
    ): ?Field {
        if ($type === '') {
            return null;
        }

        if (OptionTypeRegistry::has($type)) {
            return self::buildRegisteredOptionTypeField($type, $args, $section_proxy, $index);
        }

        $mapped = self::mapFieldType($type);
        $name = isset($args['name']) && is_string($args['name']) && $args['name'] !== ''
            ? $args['name']
            : sanitize_key($section_proxy->getId() . '_' . $type . '_' . $index);
        $label = isset($args['label']) && is_string($args['label']) ? $args['label'] : '';

        if (isset($args['render']) && is_callable($args['render']) && !isset($args['name'])) {
            $field = CustomField::build($name, $label);
            $field->setRenderCallback(static function (array $field_data, mixed $value) use ($args): void {
                $rendered = call_user_func($args['render'], $field_data, $value);
                if (is_string($rendered)) {
                    echo wp_kses_post($rendered);
                }
            });

            return $field;
        }

        $field = Field::make($mapped, $name, $label);
        self::applyCommonFieldArgs($field, $args);

        return $field;
    }

    private static function buildRegisteredOptionTypeField(
        string $type,
        array $args,
        SectionProxy $section_proxy,
        int $index
    ): Field {
        $definition = OptionTypeRegistry::get($type);
        $name = isset($args['name']) && is_string($args['name']) && $args['name'] !== ''
            ? $args['name']
            : sanitize_key($section_proxy->getId() . '_' . $type . '_' . $index);
        $label = isset($args['label']) && is_string($args['label']) ? $args['label'] : '';

        $field = CustomField::build($name, $label)
            ->setRenderCallback(static function (array $field_data, mixed $value) use ($definition, $args): void {
                $output = call_user_func($definition['render'], $field_data, $value, $args);
                if (is_string($output)) {
                    echo wp_kses_post($output);
                }
            });

        if (is_callable($definition['sanitize'])) {
            $field->setSanitizeCallback($definition['sanitize']);
        }
        if (is_callable($definition['validate'])) {
            $field->setValidateCallback($definition['validate']);
        }

        return $field;
    }

    private static function applyCommonFieldArgs(Field $field, array $args): void
    {
        if (array_key_exists('default', $args)) {
            $field->setDefault($args['default']);
        }
        if (isset($args['placeholder']) && is_string($args['placeholder'])) {
            $field->setPlaceholder($args['placeholder']);
        }
        if (isset($args['description']) && is_string($args['description'])) {
            $field->setDescription($args['description']);
        } elseif (isset($args['desc']) && is_string($args['desc'])) {
            $field->setDescription($args['desc']);
        }
        if (isset($args['required']) && (bool) $args['required'] === true) {
            $field->setRequired(true);
        }
        if (isset($args['options'])) {
            $options = $args['options'];
            if (is_callable($options)) {
                $options = call_user_func($options);
            }
            if (is_array($options)) {
                $field->setOptions($options);
            }
        }
        if (isset($args['validation']) && is_array($args['validation'])) {
            $field->setValidation($args['validation']);
        }
        if (isset($args['conditional_logic']) && is_array($args['conditional_logic'])) {
            $field->setConditionalLogic($args['conditional_logic']);
        }
        if (isset($args['attributes']) && is_array($args['attributes'])) {
            $field->addArg('attributes', $args['attributes']);
        }
        if (isset($args['custom_attributes']) && is_array($args['custom_attributes'])) {
            $field->addArg('attributes', $args['custom_attributes']);
        }
        if (
            isset($args['type'])
            && is_string($args['type'])
            && in_array($args['type'], ['number', 'email', 'url'], true)
            && $field->getType() === 'text'
        ) {
            $field->addArg('input_type', $args['type']);
        }
    }

    private static function mapFieldType(string $type): string
    {
        return match ($type) {
            'choices' => 'radio',
            'select-multiple', 'select_multiple' => 'multiselect',
            'code-editor', 'code_editor' => 'textarea',
            default => $type,
        };
    }

    private static function registerLifecycleHooks(OptionsPage $page, array $config, string $hook_prefix): void
    {
        $option_name = $page->getOptionName();
        if (isset(self::$registered_lifecycle[$option_name])) {
            return;
        }

        add_filter(
            'pre_update_option_' . $option_name,
            static function (mixed $new_value, mixed $old_value, string $option) use ($config, $hook_prefix): mixed {
                do_action('hyperfields/settings/before_save', $new_value, $old_value, $option, $config);
                do_action($hook_prefix . '_before_save', $new_value, $old_value, $option, $config);

                $validated = apply_filters('hyperfields/settings/validate', $new_value, $old_value, $option, $config);
                $validated = apply_filters($hook_prefix . '_validate', $validated, $old_value, $option, $config);

                if ($validated instanceof WP_Error) {
                    if (function_exists('add_settings_error')) {
                        add_settings_error(
                            $option,
                            'hyperfields_settings_validation_error',
                            'Settings validation failed.',
                            'error'
                        );
                    }

                    return $old_value;
                }

                return $validated;
            },
            10,
            3
        );

        add_action(
            'updated_option_' . $option_name,
            static function (mixed $old_value, mixed $new_value, string $option) use ($config, $hook_prefix): void {
                do_action('hyperfields/settings/after_save', $new_value, $old_value, $option, $config);
                do_action($hook_prefix . '_after_save', $new_value, $old_value, $option, $config);
            },
            10,
            3
        );

        self::$registered_lifecycle[$option_name] = true;
    }
}
