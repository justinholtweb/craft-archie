<?php

namespace justinholtweb\archie\blueprints;

use craft\helpers\Json;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\helpers\TypeResolver;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\models\Blueprint;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a blueprint back out.
 *
 * An export is only useful if someone is willing to read it, so this does the tidying a
 * person would do by hand: shorthands instead of class names, bare handles instead of
 * one-key mappings, and no lines stating that an empty thing is empty.
 */
class Dumper
{
    /** Keys that come first in every component, in this order. */
    private const KEY_ORDER = ['handle', 'name', 'type', 'uri', 'template', 'description'];

    public function dump(Blueprint $blueprint, ?string $format = null): string
    {
        $format ??= $blueprint->format;
        $data = $this->toArray($blueprint);

        if ($format === 'json') {
            return Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        return Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    public function toArray(Blueprint $blueprint): array
    {
        $data = ['archie' => $blueprint->version];

        if ($blueprint->name !== '') {
            $data['name'] = $blueprint->name;
        }
        if ($blueprint->description !== '') {
            $data['description'] = $blueprint->description;
        }
        if ($blueprint->vars !== []) {
            $data['vars'] = $blueprint->vars;
        }

        // Emit in the order an apply runs, so the file reads top to bottom in the same
        // order its dependencies resolve.
        $ordered = array_intersect_key(
            array_merge(array_fill_keys(Plugin::getInstance()->handlers->types(), []), $blueprint->components),
            $blueprint->components
        );

        foreach ($ordered as $type => $specs) {
            if ($specs === []) {
                continue;
            }
            $data[$type] = array_values(array_map(fn(array $spec) => $this->tidy($spec, $type), $specs));
        }

        return $data;
    }

    /** Makes one component spec readable. */
    private function tidy(array $spec, string $type): array
    {
        unset($spec['__derived']);

        // A route's identity is its URI; carrying a duplicate `handle` beside it is noise.
        if ($type === 'routes') {
            unset($spec['handle'], $spec['name']);
        }

        if (isset($spec['type']) && is_string($spec['type']) && str_contains($spec['type'], '\\')) {
            $spec['type'] = TypeResolver::shorthand($spec['type']);
        }

        if (isset($spec['fieldLayout']) && is_array($spec['fieldLayout'])) {
            $spec['fieldLayout'] = FieldLayoutHelper::toBlueprint($spec['fieldLayout']);
        }

        $spec = $this->prune($spec);

        // A name that is just the handle spelled out adds nothing.
        if (isset($spec['handle'], $spec['name']) && $this->isDerivedName((string)$spec['handle'], (string)$spec['name'])) {
            unset($spec['name']);
        }

        return $this->order($spec);
    }

    /**
     * Drops values that say nothing: nulls, empty strings and empty lists. `false` and
     * `0` stay, because those are decisions.
     */
    private function prune(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $child) {
            $child = $this->prune($child);

            if ($child === null || $child === '' || $child === []) {
                continue;
            }

            $out[$key] = $child;
        }

        return $out;
    }

    private function isDerivedName(string $handle, string $name): bool
    {
        return $name === ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $handle));
    }

    private function order(array $spec): array
    {
        $ordered = [];

        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $spec)) {
                $ordered[$key] = $spec[$key];
                unset($spec[$key]);
            }
        }

        // `fieldLayout` is the longest thing in any spec, so it goes last whatever else
        // is present.
        $layout = $spec['fieldLayout'] ?? null;
        unset($spec['fieldLayout']);

        $ordered += $spec;

        if ($layout !== null) {
            $ordered['fieldLayout'] = $layout;
        }

        return $ordered;
    }
}
