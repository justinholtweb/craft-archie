<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Entry types.
 *
 * Craft 5 promoted these to top-level, shared components — the single biggest reason a
 * Craft 4 blueprint cannot simply be re-run. An entry type here is defined once and
 * referenced by any number of sections and Matrix fields.
 */
class EntryTypeHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = [
        'name', 'handle', 'description', 'icon', 'color', 'hasTitleField',
        'titleTranslationMethod', 'titleTranslationKeyFormat', 'titleFormat',
        'allowLineBreaksInTitles', 'showSlugField', 'slugTranslationMethod',
        'slugTranslationKeyFormat', 'showStatusField', 'uiLabelFormat',
    ];

    public static function type(): string
    {
        return 'entryTypes';
    }

    public static function label(): string
    {
        return 'Entry types';
    }

    public static function singular(): string
    {
        return 'entry type';
    }

    public static function stage(): int
    {
        return 60;
    }

    public static function icon(): string
    {
        return 'files';
    }

    public function all(): array
    {
        return Craft::$app->getEntries()->getAllEntryTypes();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getEntries()->getEntryTypeByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var EntryType $component */
        $dump = $this->read($component, self::ATTRIBUTES);
        $dump['fieldLayout'] = FieldLayoutHelper::dump($component->getFieldLayout());

        return $dump;
    }

    /**
     * Craft derives `hasTitleField` from the layout rather than storing what you set:
     * `saveEntryType()` overwrites it with whether the layout includes the title element.
     * So the way to ask for a title field is to put one in the layout, and this is where
     * a blueprint's `hasTitleField` gets turned into that.
     */
    public function normalize(array $spec): array
    {
        $spec = parent::normalize($spec);

        if (!isset($spec['fieldLayout'])) {
            return $spec;
        }

        $wantsTitle = ($spec['hasTitleField'] ?? true) !== false;
        $hasTitle = false;

        foreach ($spec['fieldLayout'] as $tabIndex => $tab) {
            foreach (($tab['elements'] ?? []) as $elementIndex => $element) {
                if (($element['kind'] ?? '') !== 'native' || !self::isTitleElement((string)($element['type'] ?? ''))) {
                    continue;
                }

                if ($wantsTitle) {
                    $hasTitle = true;
                } else {
                    unset($spec['fieldLayout'][$tabIndex]['elements'][$elementIndex]);
                }
            }

            $spec['fieldLayout'][$tabIndex]['elements'] = array_values($spec['fieldLayout'][$tabIndex]['elements'] ?? []);
        }

        if ($wantsTitle && !$hasTitle && $spec['fieldLayout'] !== []) {
            array_unshift($spec['fieldLayout'][0]['elements'], ['kind' => 'native', 'type' => 'entryTitle']);
        }

        return $spec;
    }

    private static function isTitleElement(string $type): bool
    {
        return in_array(lcfirst($type), ['entryTitle', 'title'], true);
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $entryType = $existing instanceof EntryType ? $existing : new EntryType();

        $this->assign($entryType, $spec, self::ATTRIBUTES);

        if (isset($spec['fieldLayout'])) {
            $missing = [];
            $layout = FieldLayoutHelper::build($spec['fieldLayout'], Entry::class, $missing);

            if ($missing !== []) {
                // Craft's layout builder drops unknown elements in silence. Saying so is
                // the difference between a bug report and a typo the author can fix.
                $context->warn(sprintf(
                    'Entry type “%s”: left %s out of the field layout — %s not found.',
                    $spec['handle'],
                    count($missing) === 1 ? 'one element' : count($missing) . ' elements',
                    implode(', ', array_map(fn($h) => "“{$h}”", $missing))
                ));
            }

            $layout->id = $entryType->getFieldLayout()->id;
            $layout->uid = $entryType->getFieldLayout()->uid;
            $entryType->setFieldLayout($layout);
        }

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw $this->failed($entryType, "the entry type “{$spec['handle']}”");
        }

        return $entryType;
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        $where = 'entry type “' . ($spec['handle'] ?: '?') . '”';

        if (isset($spec['color']) && $spec['color'] !== null && $spec['color'] !== '') {
            $colors = array_map(fn($case) => $case->value, \craft\enums\Color::cases());
            if (!in_array($spec['color'], $colors, true)) {
                $report->warn(
                    sprintf('“%s” is not one of Craft\'s colours (%s). It will be ignored.', $spec['color'], implode(', ', $colors)),
                    $where
                );
            }
        }

        if (($spec['hasTitleField'] ?? true) === false && ($spec['titleFormat'] ?? '') === '') {
            $report->warn(
                'Titles are turned off but no `titleFormat` was given, so entries will save with an empty title.',
                $where
            );
        }
    }

    public function delete(object $component): bool
    {
        /** @var EntryType $component */
        return Craft::$app->getEntries()->deleteEntryType($component);
    }
}
