<?php

namespace justinholtweb\archie\helpers;

/**
 * Turns friendly type shorthands in a blueprint (`plainText`, `ckeditor`, `matrix`) into
 * the fully-qualified class names Craft wants, and back again for export.
 *
 * Shorthands are derived from the registered classes themselves rather than a hard-coded
 * table, so a plugin's field type gets a shorthand the day it is installed.
 */
class TypeResolver
{
    /** Hand-written aliases, for names people reach for that the derivation would miss. */
    private const ALIASES = [
        'text' => 'craft\\fields\\PlainText',
        'textarea' => 'craft\\fields\\PlainText',
        'richtext' => 'craft\\ckeditor\\Field',
        'wysiwyg' => 'craft\\ckeditor\\Field',
        'relation' => 'craft\\fields\\Entries',
        'image' => 'craft\\fields\\Assets',
        'boolean' => 'craft\\fields\\Lightswitch',
        'switch' => 'craft\\fields\\Lightswitch',
        'select' => 'craft\\fields\\Dropdown',
    ];

    /**
     * Resolves a blueprint type string against a list of available classes.
     *
     * @param string $type an FQCN, a derived shorthand, or an alias
     * @param string[] $available every class currently registered for this kind of thing
     * @param string[]|null $ambiguous filled with the candidates when a shorthand matches more than one
     */
    public static function resolve(string $type, array $available, ?array &$ambiguous = null): ?string
    {
        $ambiguous = null;
        $type = trim($type);

        if ($type === '') {
            return null;
        }

        // An FQCN, however it was written: blueprints in YAML often single-escape
        // backslashes, and JSON blueprints double-escape them.
        $normalized = ltrim(str_replace(['\\\\', '/'], '\\', $type), '\\');
        foreach ($available as $class) {
            if (strcasecmp($class, $normalized) === 0) {
                return $class;
            }
        }

        $key = strtolower($normalized);

        $matches = [];
        foreach ($available as $class) {
            if (strtolower(self::shorthand($class)) === $key) {
                $matches[] = $class;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            $ambiguous = $matches;
            return null;
        }

        if (isset(self::ALIASES[$key]) && in_array(self::ALIASES[$key], $available, true)) {
            return self::ALIASES[$key];
        }

        return null;
    }

    /**
     * The shorthand for a class: its short name with a redundant `Field` suffix removed,
     * falling back to the namespace segment when the short name carries no information
     * of its own (`craft\ckeditor\Field` → `ckeditor`).
     */
    public static function shorthand(string $class): string
    {
        $segments = explode('\\', trim($class, '\\'));
        $short = array_pop($segments);

        $stripped = preg_replace('/(Field|FieldType|Fs|Filesystem)$/', '', $short);

        if ($stripped === '' || $stripped === null) {
            // The class is called plain `Field`; the plugin's namespace is the real name.
            $short = self::meaningfulSegment($segments) ?? $short;
        } else {
            $short = $stripped;
        }

        return lcfirst($short);
    }

    /**
     * Walks back up a namespace looking for the segment that names the plugin, skipping
     * generic containers like `fields` and the vendor prefix.
     *
     * @param string[] $segments
     */
    private static function meaningfulSegment(array $segments): ?string
    {
        $generic = ['fields', 'field', 'fs', 'filesystems', 'src', 'base', 'elements'];
        while ($segments !== []) {
            $segment = array_pop($segments);
            if (!in_array(strtolower($segment), $generic, true)) {
                return $segment;
            }
        }
        return null;
    }

    /**
     * Every shorthand currently available, mapped to its class, for docs and error messages.
     *
     * @param string[] $available
     * @return array<string, string>
     */
    public static function table(array $available): array
    {
        $table = [];
        foreach ($available as $class) {
            $table[self::shorthand($class)] = $class;
        }
        ksort($table);
        return $table;
    }
}
