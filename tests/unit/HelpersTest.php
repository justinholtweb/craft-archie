<?php

namespace justinholtweb\archie\tests\unit;

use justinholtweb\archie\components\RouteHandler;
use justinholtweb\archie\helpers\TypeResolver;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    /** @dataProvider shorthands */
    public function testShorthandsAreDerivedFromTheClass(string $class, string $expected): void
    {
        self::assertSame($expected, TypeResolver::shorthand($class));
    }

    public static function shorthands(): array
    {
        return [
            'a core field' => ['craft\\fields\\PlainText', 'plainText'],
            'a one-word core field' => ['craft\\fields\\Matrix', 'matrix'],
            // A class called plain `Field` carries no information; the plugin does.
            'a plugin field named Field' => ['craft\\ckeditor\\Field', 'ckeditor'],
            'a plugin field with a suffix' => ['verbb\\hyper\\fields\\HyperField', 'hyper'],
            'a native layout element' => ['craft\\fieldlayoutelements\\entries\\EntryTitleField', 'entryTitle'],
        ];
    }

    public function testResolvesShorthandsFqcnsAndAliases(): void
    {
        $available = ['craft\\fields\\PlainText', 'craft\\fields\\Matrix'];

        self::assertSame('craft\\fields\\PlainText', TypeResolver::resolve('plainText', $available));
        self::assertSame('craft\\fields\\PlainText', TypeResolver::resolve('craft\\fields\\PlainText', $available));
        self::assertSame('craft\\fields\\PlainText', TypeResolver::resolve('text', $available));
        self::assertNull(TypeResolver::resolve('nonsense', $available));
    }

    public function testAnAmbiguousShorthandReportsItsCandidates(): void
    {
        $available = ['one\\fields\\Thing', 'two\\fields\\Thing'];
        $ambiguous = null;

        self::assertNull(TypeResolver::resolve('thing', $available, $ambiguous));
        self::assertCount(2, $ambiguous);
    }

    public function testCompilesASimpleRouteUri(): void
    {
        self::assertSame(
            ['blog/', ['slug', '[^\/]+']],
            RouteHandler::parseUri('blog/{slug}')
        );
    }

    public function testCompilesARouteUriWithAnExplicitPattern(): void
    {
        // The pattern contains braces of its own, which is the case a lazy split breaks on.
        self::assertSame(
            ['news/', ['year', '\d{4}'], '/', ['slug', '[^\/]+']],
            RouteHandler::parseUri('news/{year:\d{4}}/{slug}')
        );
    }

    public function testARouteUriWithNoTokensIsOneLiteral(): void
    {
        self::assertSame(['about/team'], RouteHandler::parseUri('about/team'));
    }
}
