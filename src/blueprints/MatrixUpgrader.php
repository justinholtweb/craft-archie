<?php

namespace justinholtweb\archie\blueprints;

use justinholtweb\archie\helpers\HandleHelper;
use justinholtweb\archie\models\Blueprint;

/**
 * Rewrites a Craft 4 Matrix field into the Craft 5 shape.
 *
 * Craft 4 gave every Matrix field its own private block types, each with its own private
 * fields. Craft 5 replaced all of that with shared entry types and ordinary fields, which
 * is why an Architect blueprint cannot simply be re-run: the single most common thing in
 * it no longer exists.
 *
 * The rewrite is mechanical — a block type becomes an entry type, a block's fields become
 * fields — and the only judgement involved is what to call things when a name is already
 * taken. Every one of those decisions is reported as a notice, because a handle you did
 * not choose is a handle you need to be told about.
 */
class MatrixUpgrader
{
    /**
     * @param array<string, array> $components components keyed by canonical type
     * @return array<string, array> the same components with Craft 4 Matrix fields rewritten
     */
    public static function upgrade(array $components, Blueprint $blueprint): array
    {
        $taken = [
            'fields' => array_column($components['fields'] ?? [], 'handle'),
            'entryTypes' => array_column($components['entryTypes'] ?? [], 'handle'),
        ];

        foreach ($components['fields'] ?? [] as $i => $field) {
            $blockTypes = $field['settings']['blockTypes'] ?? null;

            if (!is_array($blockTypes) || $blockTypes === []) {
                continue;
            }

            $matrixHandle = (string)($field['handle'] ?? 'matrix');
            $entryTypeHandles = [];

            foreach ($blockTypes as $key => $blockType) {
                if (!is_array($blockType)) {
                    continue;
                }

                [$entryType, $newFields] = self::convertBlockType($blockType, (string)$key, $matrixHandle, $taken, $blueprint);

                $components['entryTypes'][] = $entryType;
                $taken['entryTypes'][] = $entryType['handle'];
                $entryTypeHandles[] = $entryType['handle'];

                foreach ($newFields as $newField) {
                    $components['fields'][] = $newField;
                    $taken['fields'][] = $newField['handle'];
                }
            }

            unset($field['settings']['blockTypes']);

            // Craft 4 counted blocks; Craft 5 counts entries.
            foreach (['minBlocks' => 'minEntries', 'maxBlocks' => 'maxEntries'] as $old => $new) {
                if (isset($field['settings'][$old])) {
                    $field['settings'][$new] = $field['settings'][$old];
                    unset($field['settings'][$old]);
                }
            }

            $field['settings']['entryTypes'] = $entryTypeHandles;
            $components['fields'][$i] = $field;

            $blueprint->notices[] = sprintf(
                'Matrix field “%s” used Craft 4 block types. They were converted to the entry types %s.',
                $matrixHandle,
                implode(', ', array_map(fn($h) => "“{$h}”", $entryTypeHandles))
            );
        }

        return $components;
    }

    /**
     * @param array<string, string[]> $taken handles already claimed, by type
     * @return array{0: array, 1: array<int, array>} the entry type, and the fields it needs
     */
    private static function convertBlockType(array $blockType, string $key, string $matrixHandle, array $taken, Blueprint $blueprint): array
    {
        $name = (string)($blockType['name'] ?? $key);
        $wanted = (string)($blockType['handle'] ?? HandleHelper::generate($name));

        // A Craft 4 block handle was only unique inside its own field, so `text` may well
        // already be an entry type. Qualify it with the field's handle when it clashes.
        $handle = HandleHelper::unique($wanted, $taken['entryTypes']);

        if ($handle !== $wanted) {
            $handle = HandleHelper::unique($matrixHandle . ucfirst($wanted), $taken['entryTypes']);
            $blueprint->notices[] = sprintf(
                'The block type “%s” became the entry type “%s” — “%s” was already taken.',
                $wanted,
                $handle,
                $wanted
            );
        }

        $fields = [];
        $layout = [];

        foreach (($blockType['fields'] ?? []) as $fieldKey => $blockField) {
            if (!is_array($blockField)) {
                continue;
            }

            $fieldName = (string)($blockField['name'] ?? $fieldKey);
            $wantedField = (string)($blockField['handle'] ?? HandleHelper::generate($fieldName));
            $fieldHandle = HandleHelper::unique($wantedField, $taken['fields']);

            if ($fieldHandle !== $wantedField) {
                $fieldHandle = HandleHelper::unique($wanted . ucfirst($wantedField), $taken['fields']);
                $blueprint->notices[] = sprintf(
                    'The block field “%s” became the field “%s” — “%s” was already taken.',
                    $wantedField,
                    $fieldHandle,
                    $wantedField
                );
            }

            $taken['fields'][] = $fieldHandle;

            $fields[] = array_filter([
                'handle' => $fieldHandle,
                'name' => $fieldName,
                'type' => $blockField['type'] ?? $blockField['field_type'] ?? null,
                'instructions' => $blockField['instructions'] ?? null,
                // Architect called a field's settings `typesettings`.
                'settings' => $blockField['typesettings'] ?? $blockField['settings'] ?? null,
            ], fn($value) => $value !== null && $value !== '');

            $layout[] = array_filter([
                'handle' => $fieldHandle,
                'required' => !empty($blockField['required']) ? true : null,
                'width' => $blockField['width'] ?? null,
            ], fn($value) => $value !== null);
        }

        $entryType = [
            'handle' => $handle,
            'name' => $name,
            // Craft 4 Matrix blocks had no titles, and a Craft 5 entry type used as a
            // block should not grow one.
            'hasTitleField' => false,
            'fieldLayout' => [['name' => 'Content', 'fields' => $layout]],
        ];

        return [$entryType, $fields];
    }
}
