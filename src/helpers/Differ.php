<?php

namespace justinholtweb\archie\helpers;

/**
 * Compares what a blueprint asks for against what a live component currently is.
 *
 * The comparison is one-directional on purpose: only keys the blueprint actually states
 * are looked at. A blueprint that says nothing about `searchable` is not asking for
 * `searchable` to be false, so a live `true` is not a difference.
 */
class Differ
{
    /**
     * @return array<int, array{path: string, from: mixed, to: mixed, note?: string}>
     */
    public static function compare(array $desired, array $current, string $prefix = ''): array
    {
        $changes = [];

        foreach ($desired as $key => $want) {
            $path = $prefix === '' ? (string)$key : "$prefix.$key";

            // A key the live component does not have at all is one this handler does not
            // manage — a leftover from an Architect blueprint, or a setting belonging to
            // a different field type. Reporting it as a difference would be reporting a
            // difference that no apply could ever resolve, so the plan would never come
            // clean. Nested structures stay strict: a site missing from a section's site
            // settings really is a change.
            if (!array_key_exists($key, $current) && ($prefix === '' || $prefix === 'settings')) {
                continue;
            }

            $have = $current[$key] ?? null;

            // Field layouts get their own diff so the output reads as
            // "+ heroImage" rather than an unreadable nested array dump.
            if ($key === 'fieldLayout' && is_array($want)) {
                $changes = array_merge($changes, self::compareLayout($want, is_array($have) ? $have : [], $path));
                continue;
            }

            if (is_array($want) && is_array($have) && self::isAssoc($want) && self::isAssoc($have)) {
                $changes = array_merge($changes, self::compare($want, $have, $path));
                continue;
            }

            if (!self::equal($want, $have)) {
                $changes[] = [
                    'path' => $path,
                    'from' => $have,
                    'to' => $want,
                ];
            }
        }

        return $changes;
    }

    /**
     * Diffs two canonical field-layout arrays down to individual layout elements.
     *
     * @return array<int, array{path: string, from: mixed, to: mixed, note?: string}>
     */
    public static function compareLayout(array $desired, array $current, string $path = 'fieldLayout'): array
    {
        $want = self::indexLayout($desired);
        $have = self::indexLayout($current);
        $changes = [];

        foreach ($want as $key => $el) {
            if (!isset($have[$key])) {
                $changes[] = [
                    'path' => "$path.$key",
                    'from' => null,
                    'to' => $el['tab'],
                    'note' => sprintf('added to the “%s” tab', $el['tab']),
                ];
                continue;
            }

            $old = $have[$key];
            if ($old['tab'] !== $el['tab']) {
                $changes[] = [
                    'path' => "$path.$key",
                    'from' => $old['tab'],
                    'to' => $el['tab'],
                    'note' => 'moved to a different tab',
                ];
            } elseif ($old['position'] !== $el['position']) {
                $changes[] = [
                    'path' => "$path.$key",
                    'from' => $old['position'] + 1,
                    'to' => $el['position'] + 1,
                    'note' => 'reordered',
                ];
            }

            foreach (['required', 'width', 'label', 'instructions', 'handle'] as $attr) {
                if (!array_key_exists($attr, $el['attrs'])) {
                    continue;
                }
                if (!self::equal($el['attrs'][$attr], $old['attrs'][$attr] ?? null)) {
                    $changes[] = [
                        'path' => "$path.$key.$attr",
                        'from' => $old['attrs'][$attr] ?? null,
                        'to' => $el['attrs'][$attr],
                    ];
                }
            }
        }

        foreach ($have as $key => $el) {
            if (!isset($want[$key])) {
                $changes[] = [
                    'path' => "$path.$key",
                    'from' => $el['tab'],
                    'to' => null,
                    'note' => 'removed from the layout',
                ];
            }
        }

        return $changes;
    }

    /**
     * Flattens a canonical layout into `key => [tab, position, attrs]`, where the key
     * identifies the element across tabs and reorderings.
     */
    private static function indexLayout(array $layout): array
    {
        $index = [];
        $seen = [];

        foreach ($layout as $tab) {
            $tabName = (string)($tab['name'] ?? 'Content');
            $position = 0;
            foreach (($tab['elements'] ?? []) as $el) {
                $kind = $el['kind'] ?? 'field';
                if ($kind === 'field') {
                    $key = (string)($el['handle'] ?? '?');
                } else {
                    // UI and native elements have no handle, so they are identified by
                    // type plus an occurrence counter: two headings are two elements.
                    $base = $kind . ':' . ($el['type'] ?? '?');
                    $seen[$base] = ($seen[$base] ?? -1) + 1;
                    $key = $seen[$base] > 0 ? "$base#{$seen[$base]}" : $base;
                }

                $attrs = $el;
                unset($attrs['kind'], $attrs['handle'], $attrs['type']);

                $index[$key] = [
                    'tab' => $tabName,
                    'position' => $position++,
                    'attrs' => $attrs,
                ];
            }
        }

        return $index;
    }

    /**
     * Loose equality that survives the round trip through project config, where a `false`
     * can come back as `''` and an `8` as `'8'`.
     */
    public static function equal(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b)) {
                return false;
            }
            if (count($a) !== count($b)) {
                return false;
            }
            if (self::isAssoc($a) || self::isAssoc($b)) {
                ksort($a);
                ksort($b);
                if (array_keys($a) !== array_keys($b)) {
                    return false;
                }
            }
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::equal($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }

        if (is_bool($a) || is_bool($b)) {
            return self::toBool($a) === self::toBool($b);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a === (float)$b;
        }

        return (string)($a ?? '') === (string)($b ?? '');
    }

    private static function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no', 'off'], true);
        }
        return (bool)$value;
    }

    public static function isAssoc(array $array): bool
    {
        return $array !== [] && array_keys($array) !== range(0, count($array) - 1);
    }

    /** Renders a value for a diff cell: short, unambiguous, and never a var_dump. */
    public static function render(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === '') {
            return '(empty)';
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return strlen($json) > 120 ? substr($json, 0, 117) . '…' : $json;
        }
        $string = (string)$value;
        return strlen($string) > 120 ? substr($string, 0, 117) . '…' : $string;
    }
}
