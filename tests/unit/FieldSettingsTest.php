<?php

namespace justinholtweb\archie\tests\unit;

use justinholtweb\archie\components\FieldHandler;
use justinholtweb\archie\helpers\Refs;
use PHPUnit\Framework\TestCase;

/**
 * The two ways field settings used to disagree with what Craft actually stores.
 *
 * Neither of these boots Craft, and that is the point: a settings value that needs a
 * database lookup to be understood is a settings value Archie is reading wrong. The
 * reference tests below would fatal on a null `Craft::$app` if the walk started looking
 * ordinary settings up again.
 */
class FieldSettingsTest extends TestCase
{
    public function testOrdinarySettingsAreNotReadAsSourceHandles(): void
    {
        // `viewMode` and `allowedKinds` are settings. On an Assets field, whose bare
        // source handles mean volumes, they were being looked up as volume handles and
        // reported as "volume “large”, volume “image” does not exist" on every apply.
        $settings = [
            'sources' => '*',
            'viewMode' => 'large',
            'allowedKinds' => ['image'],
            'previewMode' => 'full',
        ];

        $unresolved = [];
        $expanded = Refs::expand($settings, 'craft\\fields\\Assets', $unresolved);

        self::assertSame($settings, $expanded);
        self::assertSame([], $unresolved);
    }

    public function testAPrefixedReferenceIsStillTranslatedOutsideSources(): void
    {
        // The narrowing is about *bare* handles only. `volume:` says what it points at,
        // so `defaultUploadLocationSource` still gets resolved — and with no Craft here,
        // "still gets resolved" shows up as the lookup being attempted at all.
        $this->expectException(\Error::class);

        $unresolved = [];
        Refs::expand(
            ['defaultUploadLocationSource' => 'volume:images'],
            'craft\\fields\\Assets',
            $unresolved
        );
    }

    /** @dataProvider viewModes */
    public function testViewModeIsWrittenTheWayCraftStoresIt(array $settings, array $expected): void
    {
        self::assertSame($expected, FieldHandler::canonicalizeRelationViewMode($settings, 'craft\\fields\\Assets'));
    }

    public static function viewModes(): array
    {
        return [
            // Craft 4's spelling, and the one an Architect document carries.
            'large becomes thumbs' => [
                ['viewMode' => 'large'],
                ['viewMode' => 'thumbs'],
            ],
            'a current view mode is left alone' => [
                ['viewMode' => 'list'],
                ['viewMode' => 'list'],
            ],
            // Craft derives the grid variant from the two of them together.
            'cards plus the grid flag becomes cards-grid' => [
                ['viewMode' => 'cards', 'showCardsInGrid' => true],
                ['viewMode' => 'cards-grid', 'showCardsInGrid' => true],
            ],
            'the grid flag follows the view mode' => [
                ['viewMode' => 'list', 'showCardsInGrid' => true],
                ['viewMode' => 'list', 'showCardsInGrid' => false],
            ],
            // A field type that has no grid flag must not be given one, or the permanent
            // diff is only traded for a permanent "this component has no such setting".
            'no flag is invented' => [
                ['viewMode' => 'large', 'allowedKinds' => ['image']],
                ['viewMode' => 'thumbs', 'allowedKinds' => ['image']],
            ],
        ];
    }

    public function testAFieldWithNoSourcesIsUntouched(): void
    {
        $settings = ['viewMode' => 'large', 'multiline' => true];

        self::assertSame($settings, FieldHandler::canonicalizeRelationViewMode($settings, 'craft\\fields\\PlainText'));
    }
}
