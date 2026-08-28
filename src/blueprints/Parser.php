<?php

namespace justinholtweb\archie\blueprints;

use craft\helpers\Json;
use justinholtweb\archie\models\Blueprint;
use Symfony\Component\Yaml\Exception\ParseException as YamlParseException;
use Symfony\Component\Yaml\Yaml;
use yii\base\InvalidArgumentException;

/**
 * Reads a blueprint document into a {@see Blueprint}.
 *
 * The parser is deliberately forgiving about *shape* and strict about *meaning*. It
 * accepts the several ways people naturally write the same thing — and the shapes
 * Architect used, so a Craft 4 blueprint can be read directly — but it never guesses at
 * a value. Anything it cannot make sense of comes back as a notice for the linter.
 */
class Parser
{
    /** Every spelling of a component type that maps onto a canonical key. */
    private const TYPE_ALIASES = [
        'sitegroup' => 'siteGroups', 'sitegroups' => 'siteGroups',
        'site' => 'sites', 'sites' => 'sites',
        'filesystem' => 'filesystems', 'filesystems' => 'filesystems', 'fs' => 'filesystems',
        'volume' => 'volumes', 'volumes' => 'volumes', 'assetvolumes' => 'volumes',
        'transform' => 'transforms', 'transforms' => 'transforms',
        'assettransforms' => 'transforms', 'imagetransforms' => 'transforms',
        'field' => 'fields', 'fields' => 'fields',
        'entrytype' => 'entryTypes', 'entrytypes' => 'entryTypes',
        'section' => 'sections', 'sections' => 'sections',
        'categorygroup' => 'categoryGroups', 'categorygroups' => 'categoryGroups',
        'categories' => 'categoryGroups',
        'taggroup' => 'tagGroups', 'taggroups' => 'tagGroups', 'tags' => 'tagGroups',
        'globalset' => 'globalSets', 'globalsets' => 'globalSets', 'globals' => 'globalSets',
        'usergroup' => 'userGroups', 'usergroups' => 'userGroups',
        'route' => 'routes', 'routes' => 'routes',
    ];

    /** Top-level keys that describe the document rather than declaring components. */
    private const META_KEYS = ['archie', 'version', 'meta', 'name', 'description', 'vars', 'variables'];

    /**
     * Keys that identify a component on one particular install, and so must never travel
     * with a blueprint to another one.
     */
    private const IDENTITY_KEYS = ['id', 'uid', 'fieldLayoutId', 'structureId', 'groupId', 'sortOrder', 'dateCreated', 'dateUpdated', 'oldHandle'];

    /** Craft 4 keys with no Craft 5 equivalent, and what to say about each. */
    private const RETIRED_KEYS = [
        'titleLabel' => 'Craft 5 has no title label; the title field is labelled in the field layout.',
        'contentColumnType' => 'Craft 5 decides a field’s storage for itself.',
        'hasTitleField' => null,
    ];

    /**
     * @param string $contents the raw document
     * @param array $vars values overriding the document's own `vars`
     * @param string|null $source where it came from, for error messages
     * @throws InvalidArgumentException if the document is not parseable at all
     */
    public function parse(string $contents, array $vars = [], ?string $source = null): Blueprint
    {
        $format = self::detectFormat($contents);
        $data = $this->decode($contents, $format);

        if (!is_array($data)) {
            throw new InvalidArgumentException('A blueprint must be a mapping of component types, not a bare ' . gettype($data) . '.');
        }

        $blueprint = new Blueprint();
        $blueprint->format = $format;
        $blueprint->source = $source;

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $blueprint->version = (int)($data['archie'] ?? $data['version'] ?? $meta['version'] ?? Blueprint::SCHEMA_VERSION);
        $blueprint->name = (string)($data['name'] ?? $meta['name'] ?? '');
        $blueprint->description = (string)($data['description'] ?? $meta['description'] ?? '');

        $declared = $data['vars'] ?? $data['variables'] ?? [];
        $blueprint->vars = array_merge(is_array($declared) ? $declared : [], $vars);

        $missing = [];
        $data = $this->interpolate($data, $blueprint->vars, $missing);
        $blueprint->missingVars = array_values(array_unique($missing));

        $blueprint->components = $this->collectComponents($data, $blueprint);

        return $blueprint;
    }

    /**
     * Guesses the serialisation format. JSON is unambiguous enough to detect by its first
     * character; everything else is treated as YAML, which is also true of plain JSON
     * since YAML is a superset — but reading JSON as JSON gives far better error messages.
     */
    public static function detectFormat(string $contents): string
    {
        return str_starts_with(ltrim($contents), '{') || str_starts_with(ltrim($contents), '[')
            ? 'json'
            : 'yaml';
    }

    private function decode(string $contents, string $format): mixed
    {
        if (trim($contents) === '') {
            throw new InvalidArgumentException('The blueprint is empty.');
        }

        if ($format === 'json') {
            try {
                return Json::decode($contents);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('The blueprint is not valid JSON: ' . $e->getMessage(), 0, $e);
            }
        }

        try {
            return Yaml::parse($contents);
        } catch (YamlParseException $e) {
            throw new InvalidArgumentException('The blueprint is not valid YAML: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Replaces `{{ name }}` placeholders throughout the document.
     *
     * A string that is *only* a placeholder takes the variable's real type, so
     * `enabled: "{{ live }}"` with `live: false` yields a boolean, not the string "false".
     */
    private function interpolate(mixed $value, array $vars, array &$missing): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $newKey = is_string($key) ? $this->interpolate($key, $vars, $missing) : $key;
                $out[is_string($newKey) ? $newKey : $key] = $this->interpolate($child, $vars, $missing);
            }
            return $out;
        }

        if (!is_string($value) || !str_contains($value, '{{')) {
            return $value;
        }

        if (preg_match('/^\s*\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}\s*$/', $value, $m)) {
            if (array_key_exists($m[1], $vars)) {
                return $vars[$m[1]];
            }
            $missing[] = $m[1];
            return $value;
        }

        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/', function(array $m) use ($vars, &$missing) {
            if (array_key_exists($m[1], $vars)) {
                $replacement = $vars[$m[1]];
                return is_scalar($replacement) ? (string)$replacement : $m[0];
            }
            $missing[] = $m[1];
            return $m[0];
        }, $value);
    }

    /**
     * Pulls the component declarations out of the document, canonicalising type keys and
     * normalising each spec.
     */
    private function collectComponents(array $data, Blueprint $blueprint): array
    {
        $components = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string)$key), array_map('strtolower', self::META_KEYS), true)) {
                continue;
            }

            $type = self::TYPE_ALIASES[strtolower((string)$key)] ?? null;

            if ($type === null) {
                $blueprint->notices[] = sprintf('Ignored unknown top-level key “%s”.', $key);
                continue;
            }

            if ($value === null || $value === []) {
                continue;
            }

            if (!is_array($value)) {
                $blueprint->notices[] = sprintf('Ignored “%s”: expected a list or mapping of components.', $key);
                continue;
            }

            foreach ($this->toList($value, $blueprint, $type) as $spec) {
                $components[$type][] = $spec;
            }
        }

        $components = MatrixUpgrader::upgrade($components, $blueprint);

        return $this->hoist($components, $blueprint);
    }

    /**
     * Accepts either a list of specs or a mapping of `handle => spec`, and returns a list.
     */
    private function toList(array $value, Blueprint $blueprint, string $type): array
    {
        $specs = [];
        $isMap = $value !== [] && array_keys($value) !== range(0, count($value) - 1);

        foreach ($value as $key => $spec) {
            if (!is_array($spec)) {
                // `routes: { "blog/{slug}": "blog/_entry" }` and bare string entries.
                $spec = $isMap ? ['handle' => (string)$key, 'value' => $spec] : ['handle' => (string)$spec];
            } elseif ($isMap && !isset($spec['handle'])) {
                $spec['handle'] = (string)$key;
            }

            $specs[] = $this->normalizeSpec($spec, $type, $blueprint);
        }

        return $specs;
    }

    /**
     * Smooths over the shapes a spec can legitimately arrive in, including the ones
     * Architect wrote.
     */
    private function normalizeSpec(array $spec, string $type, Blueprint $blueprint): array
    {
        // Architect called it `field_type`; Craft calls it `type`.
        if (isset($spec['field_type']) && !isset($spec['type'])) {
            $spec['type'] = $spec['field_type'];
        }
        unset($spec['field_type']);

        // Field groups were removed in Craft 5. Carrying the key forward would only
        // produce a confusing "unknown setting" further down.
        if ($type === 'fields' && isset($spec['group'])) {
            $blueprint->notices[] = sprintf(
                'Field “%s” declares a group (“%s”). Craft 5 removed field groups, so it was ignored.',
                $spec['handle'] ?? $spec['name'] ?? '?',
                is_string($spec['group']) ? $spec['group'] : 'group'
            );
            unset($spec['group']);
        }

        if (isset($spec['fieldLayout'])) {
            $spec['fieldLayout'] = self::normalizeLayout($spec['fieldLayout']);
        }

        foreach (self::IDENTITY_KEYS as $key) {
            unset($spec[$key]);
        }

        foreach (self::RETIRED_KEYS as $key => $explanation) {
            if ($explanation === null || !array_key_exists($key, $spec)) {
                continue;
            }
            $blueprint->notices[] = sprintf(
                'Dropped `%s` from “%s”. %s',
                $key,
                $spec['handle'] ?? $spec['name'] ?? '?',
                $explanation
            );
            unset($spec[$key]);
        }

        // Architect called a component's settings `typesettings`.
        if (isset($spec['typesettings']) && !isset($spec['settings'])) {
            $spec['settings'] = $spec['typesettings'];
        }
        unset($spec['typesettings']);

        // Some documents nest settings, some spread them across the top level. Both are
        // fine; the handlers only ever read `settings`.
        if (isset($spec['settings']) && !is_array($spec['settings'])) {
            unset($spec['settings']);
        }

        return $spec;
    }

    /**
     * Normalises the three ways a field layout gets written into one list of tabs.
     *
     * - `[handleA, handleB]`                     → a single “Content” tab
     * - `{ "Content": [handleA], "SEO": [...] }` → Architect's tab map
     * - `[{ name: Content, fields: [...] }]`     → Archie's own form
     */
    public static function normalizeLayout(mixed $layout): array
    {
        if (!is_array($layout) || $layout === []) {
            return [];
        }

        $isMap = array_keys($layout) !== range(0, count($layout) - 1);

        if ($isMap) {
            $tabs = [];
            foreach ($layout as $name => $elements) {
                $tabs[] = [
                    'name' => (string)$name,
                    'fields' => self::normalizeLayoutElements($elements),
                ];
            }
            return $tabs;
        }

        // A flat list of handles, with no tab structure at all.
        $looksLikeTabs = false;
        foreach ($layout as $entry) {
            if (is_array($entry) && (isset($entry['fields']) || isset($entry['elements']))) {
                $looksLikeTabs = true;
                break;
            }
        }

        if (!$looksLikeTabs) {
            return [[
                'name' => 'Content',
                'fields' => self::normalizeLayoutElements($layout),
            ]];
        }

        $tabs = [];
        foreach ($layout as $i => $tab) {
            $tabs[] = [
                'name' => (string)($tab['name'] ?? 'Tab ' . ($i + 1)),
                'fields' => self::normalizeLayoutElements($tab['fields'] ?? $tab['elements'] ?? []),
            ];
        }
        return $tabs;
    }

    /**
     * Each layout element is either a bare handle or a mapping. Bare handles become
     * `['handle' => …]` so everything downstream sees one shape.
     */
    private static function normalizeLayoutElements(mixed $elements): array
    {
        if (!is_array($elements)) {
            return [];
        }

        $out = [];
        foreach ($elements as $element) {
            if (is_string($element)) {
                $out[] = ['handle' => $element];
                continue;
            }
            if (is_array($element)) {
                $out[] = $element;
            }
        }
        return $out;
    }

    /**
     * Lifts entry types defined inline inside a section up to the top level, which is
     * where Craft 5 keeps them, leaving the section referring to them by handle.
     *
     * This is what makes Architect's section-owns-its-entry-types blueprints readable
     * here, and it is also just a nicer way to write a one-section blueprint.
     */
    private function hoist(array $components, Blueprint $blueprint): array
    {
        foreach (['sections'] as $ownerType) {
            foreach ($components[$ownerType] ?? [] as $i => $spec) {
                if (!isset($spec['entryTypes']) || !is_array($spec['entryTypes'])) {
                    continue;
                }

                $refs = [];
                foreach ($spec['entryTypes'] as $entryType) {
                    if (is_string($entryType)) {
                        $refs[] = $entryType;
                        continue;
                    }
                    if (!is_array($entryType)) {
                        continue;
                    }

                    $entryType = $this->normalizeSpec($entryType, 'entryTypes', $blueprint);

                    if (!isset($entryType['handle'])) {
                        // Architect let a section's single entry type inherit the
                        // section's handle, and Craft still does this for new sections.
                        $entryType['handle'] = $spec['handle'] ?? null;
                        $entryType['name'] ??= $spec['name'] ?? null;
                    }

                    if ($entryType['handle'] === null) {
                        $blueprint->notices[] = sprintf(
                            'Skipped an inline entry type on “%s”: it has no handle and none could be inherited.',
                            $spec['handle'] ?? '?'
                        );
                        continue;
                    }

                    $refs[] = $entryType['handle'];
                    $components['entryTypes'][] = $entryType;
                }

                $components[$ownerType][$i]['entryTypes'] = $refs;
            }
        }

        // A hoisted entry type may duplicate one declared at the top level; the explicit
        // top-level declaration is the one that survives.
        if (isset($components['entryTypes'])) {
            $components['entryTypes'] = $this->dedupe($components['entryTypes']);
        }

        return $components;
    }

    /** Keeps the first spec for each handle, merging later ones underneath it. */
    private function dedupe(array $specs): array
    {
        $byHandle = [];
        foreach ($specs as $spec) {
            $handle = $spec['handle'] ?? null;
            if ($handle === null) {
                $byHandle[] = $spec;
                continue;
            }
            // The first declaration wins: an explicit top-level entry type outranks
            // one hoisted out of a section, and only fills gaps from the later spec.
            $byHandle[$handle] = isset($byHandle[$handle])
                ? $byHandle[$handle] + $spec
                : $spec;
        }
        return array_values($byHandle);
    }
}
