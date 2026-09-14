<?php

namespace justinholtweb\archie\helpers;

use Craft;

/**
 * Translates between the UIDs Craft stores inside field settings and the handles a human
 * writes in a blueprint.
 *
 * This is the difference between a blueprint and a chunk of project config. Project
 * config says `sources: ['section:8f2c…']`, which is true but unwritable and unreadable.
 * A blueprint says `sources: [blog]`, which is the same thing said in a way you can type.
 */
class Refs
{
    private const UID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Source prefixes Craft uses, mapped to the kind of component they point at. */
    private const PREFIX_KINDS = [
        'section' => 'section',
        'volume' => 'volume',
        'taggroup' => 'tagGroup',
        'entryType' => 'entryType',
        'field' => 'field',
        'productType' => 'productType',
    ];

    /** Field classes whose bare `sources` handles refer to a particular kind of thing. */
    private const FIELD_SOURCE_KINDS = [
        'craft\\fields\\Entries' => 'section',
        'craft\\fields\\Assets' => 'volume',
        'craft\\fields\\Categories' => 'categoryGroup',
        'craft\\fields\\Tags' => 'tagGroup',
        'craft\\fields\\Users' => 'userGroup',
        'craft\\commerce\\fields\\Products' => 'productType',
        'craft\\commerce\\fields\\Variants' => 'productType',
    ];

    /**
     * Settings keys holding a bare list of UIDs of one known kind.
     *
     * `entryTypes` is deliberately absent: Craft stores it as a list of `{uid: …}` usage
     * configs rather than bare strings, so it gets its own pair of methods below.
     */
    private const BARE_UID_KEYS = [
        'availableVolumes' => 'volume',
        'availableTransforms' => 'transform',
    ];

    /**
     * UID → handle, so exported settings read in handles.
     *
     * @param string $fieldClass the field the settings belong to, used to disambiguate `group:`
     */
    public static function collapse(array $settings, string $fieldClass = ''): array
    {
        return self::walk($settings, $fieldClass, false);
    }

    /**
     * Handle → UID, so a hand-written blueprint can be handed to Craft.
     *
     * @param string[] $unresolved filled with every reference that pointed at nothing
     */
    public static function expand(array $settings, string $fieldClass = '', array &$unresolved = []): array
    {
        $unresolved = [];
        return self::walk($settings, $fieldClass, true, $unresolved);
    }

    /**
     * Whether these settings refer to another component, decided by looking at the shape
     * of the settings alone. Nothing is queried, because this is what the planner uses to
     * decide that a field has to be saved twice — once before the things it points at
     * exist, and again once they do.
     */
    public static function hasRefs(array $settings): bool
    {
        foreach ($settings as $key => $value) {
            if (in_array($key, ['sources', 'source', 'entryTypes'], true) && $value !== null && $value !== []) {
                return true;
            }
            if (isset(self::BARE_UID_KEYS[$key]) && $value !== null && $value !== []) {
                return true;
            }
            if (is_array($value) && self::hasRefs($value)) {
                return true;
            }
            if (is_string($value) && str_contains($value, ':')) {
                $prefix = explode(':', $value, 2)[0];
                if (isset(self::PREFIX_KINDS[$prefix]) || $prefix === 'group') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Writes a field's source handles in the one form both a dump and a spec will agree
     * on: prefixed, but still handles.
     *
     * A blueprint may say `sources: [blog]`, and a dump always says
     * `sources: ['section:blog']`. Without this the two would differ on every plan for
     * ever, which is worse than useless — it is a diff tool crying wolf.
     */
    public static function canonicalize(array $settings, string $fieldClass): array
    {
        $kind = self::FIELD_SOURCE_KINDS[ltrim($fieldClass, '\\')] ?? null;

        if ($kind === null) {
            return $settings;
        }

        $prefix = self::prefixFor($kind);

        if ($prefix === '') {
            return $settings;
        }

        foreach (['source', 'sources'] as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $settings[$key] = is_array($settings[$key])
                ? array_map(fn($value) => self::prefixOne($value, $prefix), $settings[$key])
                : self::prefixOne($settings[$key], $prefix);
        }

        return $settings;
    }

    private static function prefixOne(mixed $value, string $prefix): mixed
    {
        if (!is_string($value) || $value === '' || $value === '*' || str_contains($value, ':')) {
            return $value;
        }

        return $prefix . $value;
    }

    /**
     * Craft stores a Matrix or CKEditor field's entry types as `{uid: …}` usage configs.
     * A blueprint writes handles, keeping the override keys when there are any.
     */
    public static function collapseEntryTypes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entryType) {
            if (is_string($entryType)) {
                $out[] = self::looksLikeUid($entryType)
                    ? (Craft::$app->getEntries()->getEntryTypeByUid($entryType)?->handle ?? $entryType)
                    : $entryType;
                continue;
            }

            if (!is_array($entryType) || !isset($entryType['uid'])) {
                continue;
            }

            $handle = Craft::$app->getEntries()->getEntryTypeByUid($entryType['uid'])?->handle;
            if ($handle === null) {
                continue;
            }

            $overrides = array_diff_key($entryType, array_flip(['uid']));
            $out[] = $overrides === [] ? $handle : ['handle' => $handle] + $overrides;
        }

        return $out;
    }

    /**
     * The inverse: blueprint handles back into the `{uid: …}` configs Craft's
     * `Matrix::setEntryTypes()` understands. Handles that match nothing are reported
     * rather than dropped, so an apply never silently produces an empty Matrix.
     *
     * @param string[] $unresolved
     */
    public static function expandEntryTypes(mixed $value, array &$unresolved = []): array
    {
        if (!is_array($value)) {
            return [];
        }

        $entriesService = Craft::$app->getEntries();
        $out = [];

        foreach ($value as $entryType) {
            $overrides = [];

            if (is_array($entryType)) {
                $handle = (string)($entryType['handle'] ?? $entryType['uid'] ?? '');
                // A `handle` key here is the lookup, not an override — an override of the
                // handle is what `as` means.
                $overrides = array_diff_key($entryType, array_flip(['handle', 'uid']));
                if (isset($overrides['as'])) {
                    $overrides['handle'] = $overrides['as'];
                    unset($overrides['as']);
                }
            } else {
                $handle = (string)$entryType;
            }

            if ($handle === '') {
                continue;
            }

            $uid = self::looksLikeUid($handle)
                ? $handle
                : $entriesService->getEntryTypeByHandle($handle)?->uid;

            if ($uid === null) {
                $unresolved[] = "entry type “{$handle}”";
                continue;
            }

            $out[] = ['uid' => $uid] + $overrides;
        }

        return $out;
    }

    private static function walk(
        array $settings,
        string $fieldClass,
        bool $toUid,
        array &$unresolved = [],
        ?string $bareKind = null,
        bool $inSources = false,
    ): array {
        $defaultKind = self::FIELD_SOURCE_KINDS[ltrim($fieldClass, '\\')] ?? null;

        foreach ($settings as $key => $value) {
            $keyKind = self::BARE_UID_KEYS[$key] ?? null;
            $keyIsSources = $key === 'source' || $key === 'sources';

            if (is_array($value)) {
                $settings[$key] = self::walk(
                    $value,
                    $fieldClass,
                    $toUid,
                    $unresolved,
                    $keyKind ?? $bareKind,
                    $keyIsSources || $inSources,
                );
                continue;
            }

            if (!is_string($value) || $value === '' || $value === '*') {
                continue;
            }

            // A bare list like `entryTypes: [article, aside]` — no prefix to read.
            $kind = $keyKind ?? ($keyIsSources ? null : $bareKind);
            if ($kind !== null && !str_contains($value, ':')) {
                $settings[$key] = self::translate($value, $kind, $toUid, $unresolved) ?? $value;
                continue;
            }

            // A bare handle only stands in for the field's own source kind *inside*
            // `sources`. Everywhere else it is an ordinary setting, and reading
            // `viewMode: large` on an Assets field as a volume handle produces a warning
            // about a volume nobody mentioned. A prefixed `volume:images` is still
            // translated wherever it appears, which is what the other source-ish keys —
            // `defaultUploadLocationSource` and friends — actually hold.
            $settings[$key] = self::translateRef(
                $value,
                $keyIsSources || $inSources ? $defaultKind : null,
                $toUid,
                $unresolved,
            );
        }

        return $settings;
    }

    /**
     * Translates one reference string, which is either `prefix:identifier` or a bare
     * identifier standing in for the field's own default source kind.
     */
    private static function translateRef(string $value, ?string $defaultKind, bool $toUid, array &$unresolved): string
    {
        if (str_contains($value, ':')) {
            [$prefix, $identifier] = explode(':', $value, 2);
            $kind = self::PREFIX_KINDS[$prefix] ?? null;

            if ($kind === null && $prefix === 'group') {
                // `group:` means a category group on a Categories field and a user group
                // on a Users field; when the field does not say, try both.
                $kind = in_array($defaultKind, ['categoryGroup', 'userGroup'], true) ? $defaultKind : 'group';
            }

            if ($kind === null) {
                return $value;
            }

            $translated = self::translate($identifier, $kind, $toUid, $unresolved);
            return $translated === null ? $value : "$prefix:$translated";
        }

        if ($defaultKind === null || self::looksLikeUid($value) === $toUid) {
            // Nothing to do: either we do not know what it points at, or it is already
            // in the form we are converting to.
            return $value;
        }

        $translated = self::translate($value, $defaultKind, $toUid, $unresolved);
        if ($translated === null) {
            return $value;
        }

        return $toUid ? self::prefixFor($defaultKind) . $translated : self::stripPrefix($translated);
    }

    /**
     * Looks one identifier up. Returns null when nothing matches, which the callers treat
     * as "leave it exactly as it was" rather than "write a broken value".
     */
    private static function translate(string $identifier, string $kind, bool $toUid, array &$unresolved): ?string
    {
        if ($toUid) {
            if (self::looksLikeUid($identifier)) {
                return $identifier;
            }
            $uid = self::uidFor($kind, $identifier);
            if ($uid === null) {
                $unresolved[] = "$kind “{$identifier}”";
            }
            return $uid;
        }

        if (!self::looksLikeUid($identifier)) {
            return $identifier;
        }

        return self::handleFor($kind, $identifier);
    }

    private static function uidFor(string $kind, string $handle): ?string
    {
        $component = match ($kind) {
            'section' => Craft::$app->getEntries()->getSectionByHandle($handle),
            'entryType' => Craft::$app->getEntries()->getEntryTypeByHandle($handle),
            'volume' => Craft::$app->getVolumes()->getVolumeByHandle($handle),
            'transform' => Craft::$app->getImageTransforms()->getTransformByHandle($handle),
            'categoryGroup' => Craft::$app->getCategories()->getGroupByHandle($handle),
            'tagGroup' => Craft::$app->getTags()->getTagGroupByHandle($handle),
            'userGroup' => Craft::$app->getUserGroups()->getGroupByHandle($handle),
            'field' => Craft::$app->getFields()->getFieldByHandle($handle),
            // An unqualified `group:` — a category group is far more likely inside field
            // settings, but fall back to a user group so neither is silently wrong.
            'group' => Craft::$app->getCategories()->getGroupByHandle($handle)
                ?? Craft::$app->getUserGroups()->getGroupByHandle($handle),
            default => null,
        };

        return $component?->uid;
    }

    private static function handleFor(string $kind, string $uid): ?string
    {
        $component = match ($kind) {
            'section' => Craft::$app->getEntries()->getSectionByUid($uid),
            'entryType' => Craft::$app->getEntries()->getEntryTypeByUid($uid),
            'volume' => Craft::$app->getVolumes()->getVolumeByUid($uid),
            'transform' => self::transformByUid($uid),
            'categoryGroup' => Craft::$app->getCategories()->getGroupByUid($uid),
            'tagGroup' => self::tagGroupByUid($uid),
            'userGroup' => self::userGroupByUid($uid),
            'field' => Craft::$app->getFields()->getFieldByUid($uid),
            'group' => Craft::$app->getCategories()->getGroupByUid($uid) ?? self::userGroupByUid($uid),
            default => null,
        };

        return $component?->handle;
    }

    private static function transformByUid(string $uid): ?object
    {
        foreach (Craft::$app->getImageTransforms()->getAllTransforms() as $transform) {
            if ($transform->uid === $uid) {
                return $transform;
            }
        }
        return null;
    }

    private static function tagGroupByUid(string $uid): ?object
    {
        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            if ($group->uid === $uid) {
                return $group;
            }
        }
        return null;
    }

    private static function userGroupByUid(string $uid): ?object
    {
        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            if ($group->uid === $uid) {
                return $group;
            }
        }
        return null;
    }

    private static function prefixFor(string $kind): string
    {
        return match ($kind) {
            'section' => 'section:',
            'volume' => 'volume:',
            'tagGroup' => 'taggroup:',
            'categoryGroup', 'userGroup', 'group' => 'group:',
            'entryType' => 'entryType:',
            'productType' => 'productType:',
            default => '',
        };
    }

    private static function stripPrefix(string $value): string
    {
        return str_contains($value, ':') ? explode(':', $value, 2)[1] : $value;
    }

    public static function looksLikeUid(string $value): bool
    {
        return (bool)preg_match(self::UID_PATTERN, $value);
    }
}
