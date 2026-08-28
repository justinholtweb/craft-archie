<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\models\SiteGroup;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Site groups.
 *
 * Site groups have no handle in Craft, only a name, so the name is the identity here.
 */
class SiteGroupHandler extends BaseComponentHandler
{
    public static function type(): string
    {
        return 'siteGroups';
    }

    public static function label(): string
    {
        return 'Site groups';
    }

    public static function singular(): string
    {
        return 'site group';
    }

    public static function stage(): int
    {
        return 10;
    }

    public static function icon(): string
    {
        return 'layers';
    }

    public function all(): array
    {
        return Craft::$app->getSites()->getAllGroups();
    }

    public function handleOf(object $component): ?string
    {
        /** @var SiteGroup $component */
        return $component->getName(false);
    }

    public function nameOf(object $component): string
    {
        /** @var SiteGroup $component */
        return $component->getName(false);
    }

    public function dump(object $component): array
    {
        /** @var SiteGroup $component */
        return ['handle' => $component->getName(false), 'name' => $component->getName(false)];
    }

    public function normalize(array $spec): array
    {
        $name = (string)($spec['name'] ?? $spec['handle'] ?? '');

        return ['handle' => $name, 'name' => $name];
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        if (($spec['name'] ?? '') === '') {
            $report->error('A site group needs a name.', 'site group');
        }
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $group = $existing instanceof SiteGroup ? $existing : new SiteGroup();
        $group->setName($spec['name']);

        if (!Craft::$app->getSites()->saveGroup($group)) {
            throw $this->failed($group, "the site group “{$spec['name']}”");
        }

        return $group;
    }

    public function delete(object $component): bool
    {
        /** @var SiteGroup $component */
        return Craft::$app->getSites()->deleteGroup($component);
    }
}
