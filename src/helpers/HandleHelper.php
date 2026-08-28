<?php

namespace justinholtweb\archie\helpers;

use craft\helpers\StringHelper;
use craft\validators\HandleValidator;

/**
 * Handle generation and validation. A blueprint may omit a handle and let Archie derive
 * one from the name, which is what makes hand-written blueprints pleasant, but the
 * derived handle still has to survive Craft's own rules.
 */
class HandleHelper
{
    /** Craft's handle rule: starts with a letter, then letters, numbers and underscores. */
    public const PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    public static function generate(string $name): string
    {
        $handle = StringHelper::toCamelCase(StringHelper::toAscii($name));
        $handle = preg_replace('/[^a-zA-Z0-9_]/', '', $handle) ?? '';

        if ($handle !== '' && !preg_match('/^[a-zA-Z]/', $handle)) {
            $handle = 'f' . ucfirst($handle);
        }

        return $handle;
    }

    public static function isValid(string $handle): bool
    {
        return (bool)preg_match(self::PATTERN, $handle);
    }

    /**
     * Whether Craft would refuse this handle because it collides with something on the
     * element or model it is attached to.
     */
    public static function isReserved(string $handle, array $extra = []): bool
    {
        $reserved = array_map('strtolower', array_merge(HandleValidator::$baseReservedWords, $extra));
        return in_array(strtolower($handle), $reserved, true);
    }

    /**
     * Makes a handle unique against a set of handles already taken, by appending a counter.
     *
     * @param string[] $taken
     */
    public static function unique(string $handle, array $taken): string
    {
        if (!in_array($handle, $taken, true)) {
            return $handle;
        }

        $i = 2;
        while (in_array("$handle$i", $taken, true)) {
            $i++;
        }
        return "$handle$i";
    }
}
