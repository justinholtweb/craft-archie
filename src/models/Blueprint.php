<?php

namespace justinholtweb\archie\models;

use craft\base\Model;

/**
 * A parsed blueprint: a declarative description of part of a Craft content model.
 *
 * A blueprint is deliberately *partial*. It states what should exist and how those
 * things should be configured; anything it does not mention is left alone. That is
 * what separates it from project config, which is an exhaustive snapshot.
 */
class Blueprint extends Model
{
    /** Blueprint schema version. Bumped only for breaking format changes. */
    public const SCHEMA_VERSION = 1;

    public int $version = self::SCHEMA_VERSION;

    public string $name = '';

    public string $description = '';

    /** Values substituted into `{{ placeholders }}` throughout the document. */
    public array $vars = [];

    /** Component specs, keyed by canonical type (`fields`, `sections`, …). */
    public array $components = [];

    /** Where this blueprint came from: an absolute path, a recipe handle, or null for pasted text. */
    public ?string $source = null;

    /** `yaml` or `json` — the format it was read in, and the default to write it back out in. */
    public string $format = 'yaml';

    /** Non-fatal notes gathered while parsing (unknown keys, applied conversions). */
    public array $notices = [];

    /** Placeholders the document used that no variable supplied a value for. */
    public array $missingVars = [];

    public function hasType(string $type): bool
    {
        return !empty($this->components[$type]);
    }

    /**
     * @return array<int, array> the specs declared for a component type
     */
    public function specs(string $type): array
    {
        return $this->components[$type] ?? [];
    }

    /**
     * @return string[] the component types this blueprint declares, in document order
     */
    public function types(): array
    {
        return array_keys(array_filter($this->components));
    }

    /** Total number of components declared across every type. */
    public function total(): int
    {
        return array_sum(array_map('count', $this->components));
    }

    public function label(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }
        if ($this->source !== null) {
            return basename($this->source);
        }
        return 'Untitled blueprint';
    }
}
