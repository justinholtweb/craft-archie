<?php

namespace justinholtweb\archie\services;

use craft\base\Component;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\helpers\Refs;
use justinholtweb\archie\models\Blueprint;

/**
 * Reads a live content model back out as a blueprint.
 *
 * The interesting part is not the reading — every handler already knows how to dump
 * itself — but the pulling: ask for a section and you want the entry types it uses, the
 * fields those layouts name, and the entry types nested inside those fields. Exporting
 * a section on its own produces a blueprint that cannot be applied anywhere.
 */
class Exporter extends Component
{
    /**
     * @param array<string, string[]> $selection type => handles, or `['*']` for all
     * @param array{dependencies?: bool, name?: string, description?: string} $options
     */
    public function export(array $selection, array $options = []): Blueprint
    {
        $handlers = Plugin::getInstance()->handlers;
        $blueprint = new Blueprint([
            'name' => (string)($options['name'] ?? ''),
            'description' => (string)($options['description'] ?? ''),
            'format' => Plugin::getInstance()->getSettings()->defaultFormat,
        ]);

        $wanted = [];

        foreach ($selection as $type => $handles) {
            $handler = $handlers->get($type);
            if ($handler === null || $handles === []) {
                continue;
            }

            $all = in_array('*', $handles, true);

            foreach ($handler->all() as $component) {
                $handle = $handler->handleOf($component);
                if ($handle !== null && ($all || in_array($handle, $handles, true))) {
                    $wanted[$type][$handle] = true;
                }
            }
        }

        if ($options['dependencies'] ?? true) {
            $wanted = $this->resolveDependencies($wanted);
        }

        // Emit in stage order, which is also the order the result has to be applied in.
        foreach ($handlers->all() as $type => $handler) {
            foreach (array_keys($wanted[$type] ?? []) as $handle) {
                $component = $handler->find((string)$handle);
                if ($component !== null) {
                    $blueprint->components[$type][] = $handler->dump($component);
                }
            }
        }

        return $blueprint;
    }

    /**
     * Walks outwards from the chosen components until nothing new turns up.
     *
     * @param array<string, array<string, true>> $wanted
     * @return array<string, array<string, true>>
     */
    private function resolveDependencies(array $wanted): array
    {
        $handlers = Plugin::getInstance()->handlers;
        $queue = [];

        foreach ($wanted as $type => $handles) {
            foreach (array_keys($handles) as $handle) {
                $queue[] = [$type, (string)$handle];
            }
        }

        while ($queue !== []) {
            [$type, $handle] = array_shift($queue);
            $handler = $handlers->get($type);

            if ($handler === null) {
                continue;
            }

            $component = $handler->find($handle);
            if ($component === null) {
                continue;
            }

            foreach ($this->dependenciesOf($type, $handler->dump($component)) as [$depType, $depHandle]) {
                if (isset($wanted[$depType][$depHandle])) {
                    continue;
                }
                $wanted[$depType][$depHandle] = true;
                $queue[] = [$depType, $depHandle];
            }
        }

        return $wanted;
    }

    /**
     * What one dumped component needs in order to be applicable on its own.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function dependenciesOf(string $type, array $dump): array
    {
        $deps = [];

        // Anything with a field layout needs every field the layout names.
        foreach (FieldLayoutHelper::referencedHandles($dump['fieldLayout'] ?? []) as $handle) {
            $deps[] = ['fields', $handle];
        }

        switch ($type) {
            case 'sections':
                foreach ($dump['entryTypes'] ?? [] as $handle) {
                    $deps[] = ['entryTypes', (string)$handle];
                }
                break;

            case 'volumes':
                foreach (array_filter([$dump['fs'] ?? null, $dump['transformFs'] ?? null]) as $handle) {
                    $deps[] = ['filesystems', (string)$handle];
                }
                break;

            case 'fields':
                $deps = array_merge($deps, $this->fieldDependencies($dump));
                break;
        }

        return $deps;
    }

    /**
     * A field's settings are where the graph gets tangled: a Matrix field names entry
     * types, a relation field names the sections or volumes it can pull from.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function fieldDependencies(array $dump): array
    {
        $deps = [];
        $settings = $dump['settings'] ?? [];

        foreach ($settings['entryTypes'] ?? [] as $entryType) {
            $handle = is_array($entryType) ? ($entryType['handle'] ?? null) : $entryType;
            if ($handle !== null) {
                $deps[] = ['entryTypes', (string)$handle];
            }
        }

        foreach (['availableVolumes' => 'volumes', 'availableTransforms' => 'transforms'] as $key => $type) {
            foreach ($settings[$key] ?? [] as $handle) {
                $deps[] = [$type, (string)$handle];
            }
        }

        $sources = $settings['sources'] ?? $settings['source'] ?? [];
        foreach ((array)$sources as $source) {
            if (!is_string($source) || $source === '*' || !str_contains($source, ':')) {
                continue;
            }

            [$prefix, $handle] = explode(':', $source, 2);

            if (Refs::looksLikeUid($handle)) {
                continue;
            }

            $type = match ($prefix) {
                'section' => 'sections',
                'volume' => 'volumes',
                'taggroup' => 'tagGroups',
                // `group:` is a category group inside a Categories field and a user group
                // inside a Users field; the field's own class settles it.
                'group' => ($dump['type'] ?? '') === 'craft\\fields\\Users' ? 'userGroups' : 'categoryGroups',
                default => null,
            };

            if ($type !== null) {
                $deps[] = [$type, $handle];
            }
        }

        return $deps;
    }
}
