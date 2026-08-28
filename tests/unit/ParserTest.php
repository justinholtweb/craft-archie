<?php

namespace justinholtweb\archie\tests\unit;

use justinholtweb\archie\blueprints\Parser;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidArgumentException;

class ParserTest extends TestCase
{
    private function parse(string $contents, array $vars = []): \justinholtweb\archie\models\Blueprint
    {
        return (new Parser())->parse($contents, $vars);
    }

    public function testReadsYamlAndJsonAlike(): void
    {
        $yaml = $this->parse("archie: 1\nfields:\n  - handle: body\n    type: plainText\n");
        $json = $this->parse('{"archie":1,"fields":[{"handle":"body","type":"plainText"}]}');

        self::assertSame('yaml', $yaml->format);
        self::assertSame('json', $json->format);
        self::assertSame($yaml->specs('fields'), $json->specs('fields'));
    }

    public function testRefusesAnUnparseableDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parse('{"unterminated": ');
    }

    public function testAcceptsAHandleKeyedMapping(): void
    {
        $blueprint = $this->parse("fields:\n  body:\n    type: plainText\n");

        self::assertSame('body', $blueprint->specs('fields')[0]['handle']);
    }

    public function testCanonicalisesTypeAliases(): void
    {
        $blueprint = $this->parse("globals:\n  - handle: footer\ncategories:\n  - handle: topics\n");

        self::assertTrue($blueprint->hasType('globalSets'));
        self::assertTrue($blueprint->hasType('categoryGroups'));
    }

    public function testSubstitutesVariables(): void
    {
        $blueprint = $this->parse("vars:\n  name: Blog\nsections:\n  - handle: '{{ name }}'\n");

        self::assertSame('Blog', $blueprint->specs('sections')[0]['handle']);
    }

    public function testAWholeValuePlaceholderKeepsItsType(): void
    {
        $blueprint = $this->parse("sections:\n  - handle: blog\n    enableVersioning: '{{ live }}'\n", ['live' => false]);

        self::assertFalse($blueprint->specs('sections')[0]['enableVersioning']);
    }

    public function testUnsuppliedVariablesAreReported(): void
    {
        $blueprint = $this->parse("sections:\n  - handle: '{{ missing }}'\n");

        self::assertSame(['missing'], $blueprint->missingVars);
    }

    public function testNormalisesAllThreeLayoutShapes(): void
    {
        $flat = Parser::normalizeLayout(['body', 'summary']);
        $map = Parser::normalizeLayout(['Content' => ['body'], 'SEO' => ['summary']]);
        $full = Parser::normalizeLayout([['name' => 'Content', 'fields' => ['body']]]);

        self::assertSame('Content', $flat[0]['name']);
        self::assertSame([['handle' => 'body'], ['handle' => 'summary']], $flat[0]['fields']);
        self::assertCount(2, $map);
        self::assertSame('SEO', $map[1]['name']);
        self::assertSame([['handle' => 'body']], $full[0]['fields']);
    }

    public function testHoistsEntryTypesOutOfSections(): void
    {
        $blueprint = $this->parse(<<<'YAML'
sections:
  - handle: news
    name: News
    type: channel
    entryTypes:
      - handle: article
        name: Article
YAML);

        self::assertSame(['article'], $blueprint->specs('sections')[0]['entryTypes']);
        self::assertSame('article', $blueprint->specs('entryTypes')[0]['handle']);
    }

    public function testAnInlineEntryTypeInheritsTheSectionHandle(): void
    {
        $blueprint = $this->parse(<<<'YAML'
sections:
  - handle: news
    name: News
    entryTypes:
      - hasTitleField: true
YAML);

        self::assertSame(['news'], $blueprint->specs('sections')[0]['entryTypes']);
        self::assertSame('news', $blueprint->specs('entryTypes')[0]['handle']);
    }

    public function testDropsFieldGroupsWithAnExplanation(): void
    {
        $blueprint = $this->parse("fields:\n  - handle: body\n    group: Common\n");

        self::assertArrayNotHasKey('group', $blueprint->specs('fields')[0]);
        self::assertStringContainsString('field groups', implode(' ', $blueprint->notices));
    }

    public function testReadsArchitectsFieldTypeAndTypesettingsKeys(): void
    {
        $blueprint = $this->parse('{"fields":[{"handle":"body","field_type":"craft\\\\fields\\\\PlainText","typesettings":{"multiline":true}}]}');
        $field = $blueprint->specs('fields')[0];

        self::assertSame('craft\\fields\\PlainText', $field['type']);
        self::assertTrue($field['settings']['multiline']);
    }

    public function testStripsIdentityKeysThatOnlyMeanSomethingOnOneInstall(): void
    {
        $blueprint = $this->parse("fields:\n  - handle: body\n    id: 41\n    uid: abc\n    sortOrder: 3\n");
        $field = $blueprint->specs('fields')[0];

        self::assertArrayNotHasKey('id', $field);
        self::assertArrayNotHasKey('uid', $field);
        self::assertArrayNotHasKey('sortOrder', $field);
    }

    public function testIgnoresUnknownTopLevelKeys(): void
    {
        $blueprint = $this->parse("widgets:\n  - handle: x\n");

        self::assertSame([], $blueprint->components);
        self::assertStringContainsString('widgets', implode(' ', $blueprint->notices));
    }
}
