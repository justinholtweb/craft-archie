<?php

namespace justinholtweb\archie\helpers;

use Craft;
use craft\base\FieldLayoutElement;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\BaseUiElement;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\Heading;
use craft\fieldlayoutelements\HorizontalRule;
use craft\fieldlayoutelements\Html;
use craft\fieldlayoutelements\LineBreak;
use craft\fieldlayoutelements\Markdown;
use craft\fieldlayoutelements\Template;
use craft\fieldlayoutelements\Tip;
use craft\models\FieldLayout;

/**
 * Converts between Craft's field layouts and the flat, handle-based shape a blueprint
 * uses. Everything Archie compares, stores and prints goes through the canonical form
 * here, so a diff never depends on a layout element's UID or database ID.
 *
 * Canonical shape:
 *
 *     [
 *       ['name' => 'Content', 'elements' => [
 *         ['kind' => 'field', 'handle' => 'body', 'required' => true, 'width' => 100],
 *         ['kind' => 'ui', 'type' => 'heading', 'heading' => 'SEO'],
 *         ['kind' => 'native', 'type' => 'entryTitle', 'required' => true],
 *       ]],
 *     ]
 */
class FieldLayoutHelper
{
    /** UI element shorthands, in both directions. */
    private const UI_ELEMENTS = [
        'heading' => Heading::class,
        'tip' => Tip::class,
        'rule' => HorizontalRule::class,
        'lineBreak' => LineBreak::class,
        'template' => Template::class,
        'markdown' => Markdown::class,
        'html' => Html::class,
    ];

    /** Attributes carried on every layout element that a blueprint may set. */
    private const FIELD_ATTRS = ['required', 'width', 'label', 'instructions', 'tip', 'warning', 'includeInCards', 'providesThumbs'];

    /**
     * Turns parser-normalised tabs (`name` + `fields`) into the canonical shape.
     *
     * Attributes are only carried through when the blueprint actually stated them, so a
     * layout that says `- body` is not silently asserting `required: false`.
     */
    public static function canonicalizeSpec(array $tabs): array
    {
        $canonical = [];

        foreach ($tabs as $tab) {
            $elements = [];

            foreach (($tab['fields'] ?? $tab['elements'] ?? []) as $element) {
                // Already canonical — this is a dump being fed back in, which is what a
                // rollback does.
                if (isset($element['kind'])) {
                    $elements[] = $element;
                    continue;
                }

                if (isset($element['ui'])) {
                    $elements[] = ['kind' => 'ui', 'type' => (string)$element['ui']]
                        + array_diff_key($element, array_flip(['ui', 'kind']));
                    continue;
                }

                if (isset($element['native'])) {
                    $elements[] = ['kind' => 'native', 'type' => (string)$element['native']]
                        + array_diff_key($element, array_flip(['native', 'kind']));
                    continue;
                }

                if (!isset($element['handle'])) {
                    continue;
                }

                $canonicalElement = ['kind' => 'field', 'handle' => (string)$element['handle']];
                foreach (self::FIELD_ATTRS as $attr) {
                    if (array_key_exists($attr, $element)) {
                        $canonicalElement[$attr] = $element[$attr];
                    }
                }
                $elements[] = $canonicalElement;
            }

            $canonical[] = [
                'name' => (string)($tab['name'] ?? 'Content'),
                'elements' => $elements,
            ];
        }

        return $canonical;
    }

    /**
     * Reads a live field layout into the canonical shape, with every attribute present so
     * that an export round-trips exactly.
     */
    public static function dump(FieldLayout $layout): array
    {
        $canonical = [];

        foreach ($layout->getTabs() as $tab) {
            $elements = [];

            foreach ($tab->getElements() as $element) {
                $dumped = self::dumpElement($element);
                if ($dumped !== null) {
                    $elements[] = $dumped;
                }
            }

            $canonical[] = [
                'name' => (string)$tab->name,
                'elements' => $elements,
            ];
        }

        return $canonical;
    }

    private static function dumpElement(FieldLayoutElement $element): ?array
    {
        if ($element instanceof CustomField) {
            $field = $element->getField();
            $dumped = [
                'kind' => 'field',
                // The layout element's own handle wins: Craft 5 lets one field appear
                // twice in a layout under two different handles.
                'handle' => $element->handle ?? $field->handle,
            ];
            foreach (self::FIELD_ATTRS as $attr) {
                $dumped[$attr] = $element->$attr ?? null;
            }
            return $dumped;
        }

        if ($element instanceof BaseUiElement) {
            $type = array_search($element::class, self::UI_ELEMENTS, true) ?: $element::class;
            $dumped = ['kind' => 'ui', 'type' => $type, 'width' => $element->width];
            foreach (['heading', 'tip', 'style', 'template', 'content', 'html'] as $attr) {
                if (property_exists($element, $attr)) {
                    $dumped[$attr] = $element->$attr;
                }
            }
            return $dumped;
        }

        if ($element instanceof BaseField) {
            $dumped = ['kind' => 'native', 'type' => TypeResolver::shorthand($element::class)];
            foreach (self::FIELD_ATTRS as $attr) {
                $dumped[$attr] = $element->$attr ?? null;
            }
            return $dumped;
        }

        return ['kind' => 'other', 'type' => $element::class, 'width' => $element->width];
    }

    /**
     * Builds a real field layout from the canonical shape.
     *
     * Craft's own `setElements()` skips an element whose field is missing *without
     * telling anyone*, so this resolves fields itself and reports what it could not find
     * rather than quietly shipping a shorter layout than the blueprint asked for.
     *
     * @param string[] $missing filled with the handles that could not be resolved
     */
    public static function build(array $canonical, string $elementType, array &$missing = []): FieldLayout
    {
        $missing = [];
        $fieldsService = Craft::$app->getFields();
        $layout = new FieldLayout(['type' => $elementType]);
        $tabs = [];

        foreach ($canonical as $tab) {
            $configs = [];

            foreach (($tab['elements'] ?? []) as $element) {
                $kind = $element['kind'] ?? 'field';

                if ($kind === 'field') {
                    $handle = (string)($element['handle'] ?? '');
                    $field = $handle !== '' ? $fieldsService->getFieldByHandle($handle) : null;

                    if ($field === null) {
                        $missing[] = $handle;
                        continue;
                    }

                    $config = ['type' => CustomField::class, 'fieldUid' => $field->uid];

                    // Only override the handle when the layout genuinely renames the
                    // field; setting it unconditionally would make every element look
                    // like a multi-instance override.
                    if ($handle !== $field->handle) {
                        $config['handle'] = $handle;
                    }
                } else {
                    $class = self::resolveElementClass($kind, (string)($element['type'] ?? ''));
                    if ($class === null) {
                        $missing[] = ($element['type'] ?? '?') . ' (layout element)';
                        continue;
                    }
                    $config = ['type' => $class];
                }

                foreach ($element as $key => $value) {
                    if (in_array($key, ['kind', 'type', 'handle'], true) || $value === null) {
                        continue;
                    }
                    $config[$key] = $value;
                }

                $configs[] = $config;
            }

            $tabs[] = [
                'name' => (string)($tab['name'] ?? 'Content'),
                'elements' => $configs,
            ];
        }

        $layout->setTabs($tabs);

        return $layout;
    }

    private static function resolveElementClass(string $kind, string $type): ?string
    {
        if ($type === '') {
            return null;
        }

        if (class_exists($type) && is_subclass_of($type, FieldLayoutElement::class)) {
            return $type;
        }

        if ($kind === 'ui') {
            return self::UI_ELEMENTS[lcfirst($type)] ?? null;
        }

        foreach (self::nativeClasses() as $class) {
            if (strcasecmp(TypeResolver::shorthand($class), $type) === 0) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The native field layout elements Craft ships. Kept as a list rather than a scan so
     * that a blueprint's `native:` shorthand means the same thing on every install.
     *
     * @return string[]
     */
    public static function nativeClasses(): array
    {
        return array_values(array_filter([
            'craft\\fieldlayoutelements\\TitleField',
            'craft\\fieldlayoutelements\\entries\\EntryTitleField',
            'craft\\fieldlayoutelements\\assets\\AssetTitleField',
            'craft\\fieldlayoutelements\\assets\\AltField',
            'craft\\fieldlayoutelements\\users\\EmailField',
            'craft\\fieldlayoutelements\\users\\UsernameField',
            'craft\\fieldlayoutelements\\users\\FullNameField',
            'craft\\fieldlayoutelements\\users\\PhotoField',
            'craft\\fieldlayoutelements\\users\\AffiliatedSiteField',
            'craft\\fieldlayoutelements\\FullNameField',
        ], 'class_exists'));
    }

    /** Every field handle a canonical layout references. */
    public static function referencedHandles(array $canonical): array
    {
        $handles = [];
        foreach ($canonical as $tab) {
            foreach (($tab['elements'] ?? []) as $element) {
                if (($element['kind'] ?? 'field') === 'field' && isset($element['handle'])) {
                    $handles[] = (string)$element['handle'];
                }
            }
        }
        return array_values(array_unique($handles));
    }

    /**
     * Renders the canonical shape back into the friendliest blueprint form: a bare handle
     * when nothing is customised, a mapping when something is.
     */
    public static function toBlueprint(array $canonical): array
    {
        $tabs = [];

        foreach ($canonical as $tab) {
            $fields = [];

            foreach (($tab['elements'] ?? []) as $element) {
                $kind = $element['kind'] ?? 'field';

                if ($kind === 'field') {
                    $extras = [];
                    foreach (self::FIELD_ATTRS as $attr) {
                        $value = $element[$attr] ?? null;
                        if ($value === null || $value === '' || $value === false) {
                            continue;
                        }
                        if ($attr === 'width' && (int)$value === 100) {
                            continue;
                        }
                        $extras[$attr] = $value;
                    }

                    $fields[] = $extras === []
                        ? $element['handle']
                        : ['handle' => $element['handle']] + $extras;
                    continue;
                }

                $spec = [$kind === 'ui' ? 'ui' : 'native' => $element['type'] ?? ''];
                foreach ($element as $key => $value) {
                    if (in_array($key, ['kind', 'type'], true) || $value === null || $value === '' || $value === false) {
                        continue;
                    }
                    if ($key === 'width' && (int)$value === 100) {
                        continue;
                    }
                    $spec[$key] = $value;
                }
                $fields[] = $spec;
            }

            $tabs[] = ['name' => $tab['name'] ?? 'Content', 'fields' => $fields];
        }

        return $tabs;
    }
}
