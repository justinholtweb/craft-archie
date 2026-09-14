<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\base\FieldInterface;
use craft\fields\BaseRelationField;
use craft\fields\MissingField;
use justinholtweb\archie\helpers\HandleHelper;
use justinholtweb\archie\helpers\Refs;
use justinholtweb\archie\helpers\TypeResolver;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Custom fields.
 *
 * Fields are the awkward member of the family: a Matrix field points at entry types, and
 * those entry types' layouts point back at fields. Archie breaks the cycle by saving a
 * field once without the references it cannot yet resolve, and once more at the end of
 * the run when everything exists.
 */
class FieldHandler extends BaseComponentHandler
{
    /** Attributes every field has, whatever its type. */
    private const ATTRIBUTES = ['name', 'handle', 'instructions', 'searchable', 'translationMethod', 'translationKeyFormat'];

    public static function type(): string
    {
        return 'fields';
    }

    public static function label(): string
    {
        return 'Fields';
    }

    public static function singular(): string
    {
        return 'field';
    }

    public static function stage(): int
    {
        return 50;
    }

    public static function icon(): string
    {
        return 'pencil';
    }

    public function all(): array
    {
        return Craft::$app->getFields()->getAllFields();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getFields()->getFieldByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var FieldInterface $component */
        $dump = $this->read($component, self::ATTRIBUTES);
        $dump['type'] = $component::class;
        $dump['settings'] = $this->dumpSettings($component);

        return $dump;
    }

    private function dumpSettings(FieldInterface $field): array
    {
        $settings = $field->getSettings();

        if (isset($settings['entryTypes'])) {
            $settings['entryTypes'] = Refs::collapseEntryTypes($settings['entryTypes']);
        }

        return Refs::collapse($settings, $field::class);
    }

    public function normalize(array $spec): array
    {
        $spec = parent::normalize($spec);

        $type = (string)($spec['type'] ?? '');
        $resolved = TypeResolver::resolve($type, $this->availableTypes());

        // An unresolved type is left exactly as written so the linter can quote it back.
        $spec['type'] = $resolved ?? $type;

        if (is_array($spec['settings'] ?? null)) {
            $spec['settings'] = self::canonicalizeRelationViewMode($spec['settings'], (string)$spec['type']);
            $spec['settings'] = Refs::canonicalize($spec['settings'], (string)$spec['type']);
        } else {
            // A field with no `settings` block is not asking for its settings to be
            // emptied, so this is a value Archie supplied rather than one it was given.
            $spec['settings'] = [];
            $spec[self::DERIVED_KEY][] = 'settings';
        }

        return $spec;
    }

    /**
     * Writes a relation field's `viewMode` the way Craft is going to store it.
     *
     * `BaseRelationField::__construct()` rewrites two view modes before anything is
     * saved: Craft 4's `large` became `thumbs`, and `cards` plus `showCardsInGrid`
     * became `cards-grid`. A blueprint saying either of the old things is not wrong —
     * `large` is exactly what an Architect document carries — but left alone it plans as
     * a change on every run, because Archie compares what was asked for against what
     * Craft stored and those two never converge.
     */
    public static function canonicalizeRelationViewMode(array $settings, string $type): array
    {
        if ($type === '' || !is_subclass_of($type, BaseRelationField::class)) {
            return $settings;
        }

        $viewMode = $settings['viewMode'] ?? null;

        if ($viewMode === 'large') {
            $settings['viewMode'] = BaseRelationField::VIEW_MODE_THUMBS;
        } elseif ($viewMode === BaseRelationField::VIEW_MODE_CARDS && !empty($settings['showCardsInGrid'])) {
            $settings['viewMode'] = BaseRelationField::VIEW_MODE_CARDS_GRID;
        }

        // `showCardsInGrid` is only ever derived from the view mode, and only the field
        // types that have it get told about it — writing it onto an Assets field would
        // trade one permanent diff for a permanent "this component has no such setting".
        if (array_key_exists('showCardsInGrid', $settings)) {
            $settings['showCardsInGrid'] = ($settings['viewMode'] ?? null) === BaseRelationField::VIEW_MODE_CARDS_GRID;
        }

        return $settings;
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        $where = 'field “' . ($spec['handle'] ?: '?') . '”';
        $type = (string)($spec['type'] ?? '');

        if ($type === '') {
            $report->error('No field type. Add a `type`, e.g. `plainText` or `craft\\fields\\PlainText`.', $where);
            return;
        }

        $ambiguous = null;
        if (TypeResolver::resolve($type, $this->availableTypes(), $ambiguous) === null) {
            if ($ambiguous !== null) {
                $report->error(
                    sprintf('The type “%s” is ambiguous — it could be %s. Use the full class name.', $type, implode(' or ', $ambiguous)),
                    $where
                );
            } else {
                $report->error(
                    sprintf('Unknown field type “%s”. Is the plugin that provides it installed?', $type),
                    $where
                );
            }
            return;
        }

        if (HandleHelper::isReserved($spec['handle'] ?? '')) {
            $report->error(
                sprintf('“%s” is a reserved word and cannot be used as a field handle.', $spec['handle']),
                $where
            );
        }

        $this->lintCraft4Matrix($spec, $report, $where);
        $this->lintSettings($spec, $report, $where);
    }

    /**
     * Checks the settings against what the field type actually has.
     *
     * Craft ignores a setting a field does not define, which means a typo produces no
     * error anywhere — just a field that is not configured the way the blueprint says it
     * is, and a plan that reports the same difference for ever.
     */
    private function lintSettings(array $spec, LintReport $report, string $where): void
    {
        if ($spec['settings'] === []) {
            return;
        }

        try {
            $field = Craft::$app->getFields()->createField(['type' => $spec['type']]);
            $known = $field->settingsAttributes();
        } catch (\Throwable) {
            return;
        }

        foreach (array_keys($spec['settings']) as $key) {
            // A setting can also be exposed through a setter alone — Matrix keeps its
            // entry types that way, out of `settingsAttributes()` entirely.
            if (
                in_array($key, $known, true)
                || property_exists($field, $key)
                || method_exists($field, 'set' . ucfirst($key))
            ) {
                continue;
            }

            $report->warn(
                sprintf('`settings.%s` is not a setting of this field type, so Craft will ignore it.', $key),
                $where
            );
        }

        // Craft turns `maintainHierarchy` on for every saved Categories field unless it
        // is told otherwise, and a field maintaining hierarchy discards its relation
        // limit. Asking for both is a plan that can never converge.
        if (
            $spec['type'] === 'craft\\fields\\Categories'
            && !empty($spec['settings']['maxRelations'])
            && ($spec['settings']['maintainHierarchy'] ?? true) !== false
        ) {
            $report->warn(
                'Craft ignores `maxRelations` on a Categories field that maintains hierarchy. Add `maintainHierarchy: false` beside it, or drop the limit.',
                $where
            );
        }
    }

    /**
     * Craft 4 Matrix fields declared their own block types. Craft 5 replaced them with
     * shared entry types, so a Craft 4 blueprint applied as-is would produce an empty
     * Matrix — which is exactly the sort of quiet failure worth being loud about.
     */
    private function lintCraft4Matrix(array $spec, LintReport $report, string $where): void
    {
        $settings = $spec['settings'] ?? [];

        if (!isset($settings['blockTypes'])) {
            return;
        }

        // Reaching here means the upgrader could not make sense of the block types —
        // normally they are rewritten into entry types before a spec is ever linted.
        $report->error(
            'This field declares `blockTypes`, which Craft 4 used for Matrix blocks, in a shape Archie could not read. '
            . 'Craft 5 uses shared entry types instead: declare each block as an entry type and list their handles under `settings.entryTypes`.',
            $where
        );
    }

    /**
     * Changing a field's type rewrites its storage. Craft will do it without complaint
     * and the old values do not come back, so this is the one change Archie refuses to
     * make on the strength of a blueprint alone.
     */
    public function conflict(array $spec, object $existing): ?string
    {
        if (!isset($spec['type']) || $existing::class === $spec['type']) {
            return null;
        }

        return sprintf(
            'Already exists as a %s field. Changing it to %s discards the content already stored in it.',
            TypeResolver::shorthand($existing::class),
            TypeResolver::shorthand($spec['type'])
        );
    }

    public function needsRelink(array $spec): bool
    {
        return Refs::hasRefs($spec['settings'] ?? []);
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $unresolved = [];
        $settings = $this->expandSettings($spec, $unresolved);

        if ($unresolved !== []) {
            // Save what we can now and come back for the rest. Validation is off for this
            // first pass because a Matrix field with no entry types is not yet valid, and
            // will not be until the relink.
            $context->defer(self::type(), $spec['handle'], $spec);
            $settings = $this->stripRefs($settings);
            $this->save($spec, $existing, $settings, false);
            return $this->find($spec['handle']);
        }

        $this->save($spec, $existing, $settings, true);

        return $this->find($spec['handle']);
    }

    public function relink(array $spec, ApplyContext $context): void
    {
        $unresolved = [];
        $settings = $this->expandSettings($spec, $unresolved);

        if ($unresolved !== []) {
            $context->warn(sprintf(
                'Field “%s” still refers to %s, which does not exist. Those references were left unset.',
                $spec['handle'],
                implode(', ', array_unique($unresolved))
            ));
            $settings = $this->stripRefs($settings);
        }

        $this->save($spec, $this->find($spec['handle']), $settings, $unresolved === []);
    }

    private function expandSettings(array $spec, array &$unresolved): array
    {
        $settings = $spec['settings'] ?? [];
        $unresolved = [];

        if (array_key_exists('entryTypes', $settings)) {
            $entryTypeErrors = [];
            $settings['entryTypes'] = Refs::expandEntryTypes($settings['entryTypes'], $entryTypeErrors);
            $unresolved = array_merge($unresolved, $entryTypeErrors);
        }

        $refErrors = [];
        $settings = Refs::expand($settings, (string)($spec['type'] ?? ''), $refErrors);
        $unresolved = array_merge($unresolved, $refErrors);

        return $settings;
    }

    /** Removes the keys that point at other components, for the first of two saves. */
    private function stripRefs(array $settings): array
    {
        foreach (['entryTypes', 'sources', 'source', 'availableVolumes', 'availableTransforms'] as $key) {
            unset($settings[$key]);
        }
        return $settings;
    }

    private function save(array $spec, ?object $existing, array $settings, bool $validate): void
    {
        $config = [
            'type' => $spec['type'],
            'settings' => $settings,
        ];

        foreach (self::ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $spec)) {
                $config[$attribute] = $spec[$attribute];
            }
        }

        if ($existing !== null) {
            $config['id'] = $existing->id;
            $config['uid'] = $existing->uid;
            // Keep the column suffix Craft assigned, or every save would orphan the
            // field's existing content.
            $config['columnSuffix'] = $existing->columnSuffix ?? null;
        }

        $field = Craft::$app->getFields()->createField($config);

        if ($field instanceof MissingField) {
            throw new \RuntimeException(sprintf(
                'Craft could not create a “%s” field — the field type is not installed.',
                $spec['type']
            ));
        }

        if (!Craft::$app->getFields()->saveField($field, $validate)) {
            throw $this->failed($field, "the field “{$spec['handle']}”");
        }
    }

    public function delete(object $component): bool
    {
        /** @var FieldInterface $component */
        return Craft::$app->getFields()->deleteField($component);
    }

    /** @return string[] */
    private function availableTypes(): array
    {
        return Craft::$app->getFields()->getAllFieldTypes();
    }
}
