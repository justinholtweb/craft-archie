<?php

namespace justinholtweb\archie\tests\unit;

use justinholtweb\archie\blueprints\Parser;
use PHPUnit\Framework\TestCase;

/**
 * The Craft 4 → Craft 5 Matrix rewrite, exercised through the parser because that is
 * where it actually happens.
 */
class MatrixUpgraderTest extends TestCase
{
    private function parse(string $json): \justinholtweb\archie\models\Blueprint
    {
        return (new Parser())->parse($json);
    }

    private function architectMatrix(string $blockHandle = 'text', string $fieldHandle = 'text'): string
    {
        return json_encode([
            'fields' => [[
                'handle' => 'pageBlocks',
                'name' => 'Page Blocks',
                'field_type' => 'craft\\fields\\Matrix',
                'settings' => [
                    'minBlocks' => 1,
                    'maxBlocks' => 6,
                    'blockTypes' => [
                        'new1' => [
                            'name' => 'Text',
                            'handle' => $blockHandle,
                            'fields' => [
                                'new1' => [
                                    'name' => 'Text',
                                    'handle' => $fieldHandle,
                                    'type' => 'craft\\fields\\PlainText',
                                    'required' => true,
                                    'typesettings' => ['multiline' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ]],
        ]);
    }

    public function testABlockTypeBecomesAnEntryType(): void
    {
        $blueprint = $this->parse($this->architectMatrix());
        $entryTypes = $blueprint->specs('entryTypes');

        self::assertCount(1, $entryTypes);
        self::assertSame('text', $entryTypes[0]['handle']);
        self::assertSame('Text', $entryTypes[0]['name']);
        self::assertFalse($entryTypes[0]['hasTitleField']);
    }

    public function testABlocksFieldsBecomeOrdinaryFields(): void
    {
        $blueprint = $this->parse($this->architectMatrix());
        $handles = array_column($blueprint->specs('fields'), 'handle');

        self::assertContains('pageBlocks', $handles);
        self::assertContains('text', $handles);

        $inner = array_values(array_filter($blueprint->specs('fields'), fn($f) => $f['handle'] === 'text'))[0];
        self::assertSame('craft\\fields\\PlainText', $inner['type']);
        self::assertTrue($inner['settings']['multiline']);
    }

    public function testTheMatrixFieldEndsUpPointingAtTheEntryTypes(): void
    {
        $blueprint = $this->parse($this->architectMatrix());
        $matrix = array_values(array_filter($blueprint->specs('fields'), fn($f) => $f['handle'] === 'pageBlocks'))[0];

        self::assertSame(['text'], $matrix['settings']['entryTypes']);
        self::assertArrayNotHasKey('blockTypes', $matrix['settings']);
    }

    public function testBlockCountsBecomeEntryCounts(): void
    {
        $blueprint = $this->parse($this->architectMatrix());
        $matrix = array_values(array_filter($blueprint->specs('fields'), fn($f) => $f['handle'] === 'pageBlocks'))[0];

        self::assertSame(1, $matrix['settings']['minEntries']);
        self::assertSame(6, $matrix['settings']['maxEntries']);
        self::assertArrayNotHasKey('minBlocks', $matrix['settings']);
    }

    public function testRequiredSurvivesIntoTheLayout(): void
    {
        $blueprint = $this->parse($this->architectMatrix());
        $entryType = $blueprint->specs('entryTypes')[0];

        self::assertTrue($entryType['fieldLayout'][0]['fields'][0]['required']);
    }

    public function testAClashingBlockHandleIsQualifiedAndReported(): void
    {
        // A Craft 4 block handle was only unique inside its own field, so `text` may
        // already be taken at the top level.
        $document = json_decode($this->architectMatrix(), true);
        $document['entryTypes'] = [['handle' => 'text', 'name' => 'Existing text']];

        $blueprint = $this->parse(json_encode($document));
        $handles = array_column($blueprint->specs('entryTypes'), 'handle');

        self::assertContains('pageBlocksText', $handles);
        self::assertStringContainsString('already taken', implode(' ', $blueprint->notices));
    }

    public function testTheConversionIsAnnounced(): void
    {
        $blueprint = $this->parse($this->architectMatrix());

        self::assertStringContainsString('Craft 4 block types', implode(' ', $blueprint->notices));
    }
}
