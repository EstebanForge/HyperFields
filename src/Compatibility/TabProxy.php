<?php

declare(strict_types=1);

namespace HyperFields\Compatibility;

final class TabProxy
{
    /**
     * @var array<int, SectionProxy>
     */
    private array $sections = [];

    public function __construct(
        private readonly string $key,
        private readonly string $label
    ) {
    }

    public function add_section(string $title, array $args = []): SectionProxy
    {
        $id = isset($args['id']) && is_string($args['id']) && $args['id'] !== ''
            ? $args['id']
            : sanitize_key($this->key . '_' . $title . '_' . count($this->sections));

        $section = new SectionProxy($id, $title, $args);
        $this->sections[] = $section;

        return $section;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<int, SectionProxy>
     */
    public function getSections(): array
    {
        return $this->sections;
    }
}

