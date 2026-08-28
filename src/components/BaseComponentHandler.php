<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\base\Model;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\helpers\HandleHelper;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;
use yii\base\BaseObject;

/**
 * Shared behaviour for component handlers: handle derivation, the usual lint checks, and
 * the "save it and complain properly if Craft says no" plumbing.
 */
abstract class BaseComponentHandler extends BaseObject implements ComponentHandlerInterface
{
    /** Where a normalised spec records the keys Archie filled in rather than read. */
    public const DERIVED_KEY = '__derived';

    public function conflict(array $spec, object $existing): ?string
    {
        return null;
    }

    public static function singular(): string
    {
        return rtrim(strtolower(static::label()), 's');
    }

    public static function icon(): string
    {
        return 'cube';
    }

    public function handleOf(object $component): ?string
    {
        return $component->handle ?? null;
    }

    public function nameOf(object $component): string
    {
        return (string)($component->name ?? $this->handleOf($component) ?? '');
    }

    public function uidOf(object $component): ?string
    {
        return $component->uid ?? null;
    }

    public function find(string $handle): ?object
    {
        foreach ($this->all() as $component) {
            if ($this->handleOf($component) === $handle) {
                return $component;
            }
        }
        return null;
    }

    public function normalize(array $spec): array
    {
        $derived = $spec[self::DERIVED_KEY] ?? [];
        $spec['name'] = isset($spec['name']) ? (string)$spec['name'] : null;
        $handle = isset($spec['handle']) ? trim((string)$spec['handle']) : '';

        if ($handle === '' && $spec['name'] !== null && Plugin::getInstance()->getSettings()->generateMissingHandles) {
            $handle = HandleHelper::generate($spec['name']);
            $derived[] = 'handle';
        }

        $spec['handle'] = $handle;

        if ($spec['name'] === null || $spec['name'] === '') {
            $derived[] = 'name';
            // A handle alone is enough to identify a component; the name is only how it
            // reads in the CP, so deriving it is safe and saves a line in every spec.
            $spec['name'] = $handle !== '' ? ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $handle)) : '';
        }

        if (isset($spec['fieldLayout'])) {
            $spec['fieldLayout'] = FieldLayoutHelper::canonicalizeSpec($spec['fieldLayout']);
        }

        $spec[self::DERIVED_KEY] = array_values(array_unique($derived));

        return $spec;
    }

    /**
     * Expands a `*` key in per-site settings into one entry per site, and moves
     * top-level shorthand (`uriFormat` beside the handle) into the same place.
     *
     * This has to happen during normalisation rather than at apply time: a diff compares
     * the spec against a dump keyed by site handle, so a spec still keyed by `*` would
     * report a change on every single plan, for ever.
     *
     * @param string[] $attributes the per-site keys this component understands
     */
    protected function expandSiteSettings(array $spec, array $attributes): array
    {
        $shorthand = array_intersect_key($spec, array_flip($attributes));

        if ($shorthand !== []) {
            $spec['siteSettings'] = array_merge(['*' => $shorthand], $spec['siteSettings'] ?? []);
            $spec = array_diff_key($spec, $shorthand);
        }

        if (!isset($spec['siteSettings']) || !is_array($spec['siteSettings'])) {
            return $spec;
        }

        $wildcard = $spec['siteSettings']['*'] ?? null;

        if ($wildcard === null) {
            return $spec;
        }

        unset($spec['siteSettings']['*']);

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $stated = $spec['siteSettings'][$site->handle] ?? null;

            if ($stated === false) {
                continue;
            }

            $spec['siteSettings'][$site->handle] = array_merge(
                is_array($wildcard) ? $wildcard : [],
                is_array($stated) ? $stated : []
            );
        }

        return $spec;
    }

    /**
     * Strips a spec down to what the blueprint actually said, dropping the values Archie
     * filled in for it. An update must not overwrite a component's name with a name
     * derived from its handle, and a diff must not report one as a change.
     */
    public static function stated(array $spec): array
    {
        return array_diff_key($spec, array_flip(array_merge($spec[self::DERIVED_KEY] ?? [], [self::DERIVED_KEY])));
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        $where = static::singular() . ' “' . ($spec['handle'] ?: ($spec['name'] ?? '?')) . '”';

        if (($spec['handle'] ?? '') === '') {
            $report->error(
                Plugin::getInstance()->getSettings()->generateMissingHandles
                    ? 'No handle, and none could be derived from a name.'
                    : 'No handle. Handle generation is turned off in Archie’s settings, so every component has to state one.',
                $where
            );
        } elseif (!HandleHelper::isValid($spec['handle'])) {
            $report->error(
                sprintf('“%s” is not a valid handle. Handles start with a letter and contain only letters, numbers and underscores.', $spec['handle']),
                $where
            );
        }

        $this->lintFieldLayout($spec, $report, $blueprint, $where);
    }

    /**
     * Every field a layout names has to exist by the time the layout is saved — either
     * because it is already on the site or because this blueprint creates it. Craft's own
     * layout builder drops unknown fields without a word, so this check is the only thing
     * standing between a typo and a silently short layout.
     */
    protected function lintFieldLayout(array $spec, LintReport $report, Blueprint $blueprint, string $where): void
    {
        if (empty($spec['fieldLayout'])) {
            return;
        }

        $declared = [];
        foreach ($blueprint->specs('fields') as $field) {
            if (isset($field['handle'])) {
                $declared[] = $field['handle'];
            }
        }

        foreach (FieldLayoutHelper::referencedHandles($spec['fieldLayout']) as $handle) {
            if (in_array($handle, $declared, true)) {
                continue;
            }
            if (Craft::$app->getFields()->getFieldByHandle($handle) !== null) {
                continue;
            }
            $report->error(
                sprintf('The field layout references “%s”, which does not exist and is not created by this blueprint.', $handle),
                $where
            );
        }
    }

    public function needsRelink(array $spec): bool
    {
        return false;
    }

    public function relink(array $spec, ApplyContext $context): void
    {
    }

    /**
     * Copies the spec's values onto a Craft model, ignoring keys the blueprint uses for
     * its own purposes and keys the model does not have.
     *
     * @param string[] $attributes the attributes this handler is willing to set
     */
    protected function assign(Model $model, array $spec, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (array_key_exists($attribute, $spec)) {
                $model->$attribute = self::coerce($model, $attribute, $spec[$attribute]);
            }
        }
    }

    /**
     * Casts a blueprint's plain string into whatever the model's typed property actually
     * wants. Craft 5 types several settings as backed enums — a section's propagation
     * method, an entry type's colour — and assigning the string straight in is a fatal.
     */
    protected static function coerce(Model $model, string $attribute, mixed $value): mixed
    {
        if (!is_string($value) || !property_exists($model, $attribute)) {
            return $value;
        }

        try {
            $type = (new \ReflectionProperty($model, $attribute))->getType();
        } catch (\ReflectionException) {
            return $value;
        }

        $names = $type instanceof \ReflectionNamedType
            ? [$type->getName()]
            : array_map(fn($t) => $t->getName(), $type instanceof \ReflectionUnionType ? $type->getTypes() : []);

        foreach ($names as $name) {
            if (enum_exists($name) && is_subclass_of($name, \BackedEnum::class)) {
                return $name::tryFrom($value) ?? $value;
            }
        }

        return $value;
    }

    /**
     * Reads a set of attributes off a model into a spec array.
     *
     * @param string[] $attributes
     */
    protected function read(object $model, array $attributes): array
    {
        $dump = [];
        foreach ($attributes as $attribute) {
            $value = $model->$attribute ?? null;
            // Enum-backed settings (propagation methods, and anything a plugin adds) are
            // written in a blueprint as their string value.
            $dump[$attribute] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        return $dump;
    }

    /**
     * Turns a failed save into an exception carrying Craft's own validation messages,
     * which are almost always more useful than anything this plugin could invent.
     */
    protected function failed(Model $model, string $what): \RuntimeException
    {
        $errors = [];
        foreach ($model->getErrors() as $attribute => $messages) {
            foreach ((array)$messages as $message) {
                $errors[] = "$attribute: $message";
            }
        }

        return new \RuntimeException(
            $errors === []
                ? "Craft refused to save $what."
                : "Craft refused to save $what — " . implode('; ', $errors)
        );
    }
}
