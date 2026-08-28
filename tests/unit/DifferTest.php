<?php

namespace justinholtweb\archie\tests\unit;

use justinholtweb\archie\helpers\Differ;
use PHPUnit\Framework\TestCase;

class DifferTest extends TestCase
{
    public function testOnlyStatedKeysAreCompared(): void
    {
        $changes = Differ::compare(
            ['name' => 'Body'],
            ['name' => 'Body', 'searchable' => true, 'instructions' => 'Anything']
        );

        self::assertSame([], $changes);
    }

    public function testAStatedDifferenceIsReported(): void
    {
        $changes = Differ::compare(['name' => 'Body'], ['name' => 'Article body']);

        self::assertCount(1, $changes);
        self::assertSame('name', $changes[0]['path']);
        self::assertSame('Article body', $changes[0]['from']);
        self::assertSame('Body', $changes[0]['to']);
    }

    public function testKeysAbsentFromTheLiveComponentAreNotDifferences(): void
    {
        // A blueprint key the component has no equivalent for is ignored on apply, so
        // reporting it would produce a plan that never comes clean.
        $changes = Differ::compare(['titleLabel' => 'Headline'], ['name' => 'News']);

        self::assertSame([], $changes);
    }

    public function testUnknownSettingsKeysAreNotDifferencesEither(): void
    {
        $changes = Differ::compare(
            ['settings' => ['multiline' => true, 'blockTypes' => ['x']]],
            ['settings' => ['multiline' => true]]
        );

        self::assertSame([], $changes);
    }

    public function testAWholeMissingNestedBlockIsReportedAsOneChange(): void
    {
        // A site missing from a section's site settings really is a change — and it is
        // one change, not one per key, because the whole block is being added.
        $changes = Differ::compare(
            ['siteSettings' => ['second' => ['uriFormat' => 'x/{slug}']]],
            ['siteSettings' => ['default' => ['uriFormat' => 'x/{slug}']]]
        );

        self::assertCount(1, $changes);
        self::assertSame('siteSettings.second', $changes[0]['path']);
    }

    public function testNestedStructuresStayStrict(): void
    {
        // The skip-what-is-absent rule must not reach inside nested structures: a site
        // that exists but has the wrong URI format is a difference at the key level.
        $changes = Differ::compare(
            ['siteSettings' => ['default' => ['uriFormat' => 'new/{slug}']]],
            ['siteSettings' => ['default' => ['uriFormat' => 'old/{slug}', 'template' => 'x']]]
        );

        self::assertCount(1, $changes);
        self::assertSame('siteSettings.default.uriFormat', $changes[0]['path']);
        self::assertSame('old/{slug}', $changes[0]['from']);
    }

    public function testProjectConfigRoundTripsDoNotLookLikeChanges(): void
    {
        self::assertTrue(Differ::equal(false, ''));
        self::assertTrue(Differ::equal(8, '8'));
        self::assertTrue(Differ::equal(null, ''));
        self::assertTrue(Differ::equal(true, '1'));
        self::assertFalse(Differ::equal(true, false));
        self::assertFalse(Differ::equal('a', 'b'));
    }

    public function testLayoutDiffNamesTheElementThatMoved(): void
    {
        $before = [['name' => 'Content', 'elements' => [
            ['kind' => 'field', 'handle' => 'body'],
        ]]];
        $after = [['name' => 'Content', 'elements' => [
            ['kind' => 'field', 'handle' => 'body'],
            ['kind' => 'field', 'handle' => 'summary'],
        ]]];

        $changes = Differ::compareLayout($after, $before);

        self::assertCount(1, $changes);
        self::assertSame('fieldLayout.summary', $changes[0]['path']);
        self::assertStringContainsString('added', $changes[0]['note']);
    }

    public function testLayoutDiffReportsRemovals(): void
    {
        $before = [['name' => 'Content', 'elements' => [['kind' => 'field', 'handle' => 'body']]]];

        $changes = Differ::compareLayout([['name' => 'Content', 'elements' => []]], $before);

        self::assertCount(1, $changes);
        self::assertStringContainsString('removed', $changes[0]['note']);
    }

    public function testRepeatedUiElementsAreTrackedSeparately(): void
    {
        $layout = [['name' => 'Content', 'elements' => [
            ['kind' => 'ui', 'type' => 'heading', 'heading' => 'One'],
            ['kind' => 'ui', 'type' => 'heading', 'heading' => 'Two'],
        ]]];

        self::assertSame([], Differ::compareLayout($layout, $layout));
    }
}
