<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\models\Site;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Sites.
 *
 * Adding a site is the one thing in a blueprint that moves content rather than just
 * structure: Craft propagates every existing entry into it. The linter says so, and
 * deleting a site is never something Archie will do on its own.
 */
class SiteHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['handle', 'primary', 'hasUrls', 'enabled', 'sortOrder'];

    /** Attributes whose raw, unparsed value matters — they routinely hold `$ENV_VARS`. */
    private const RAW_ATTRIBUTES = ['name', 'language', 'baseUrl'];

    public static function type(): string
    {
        return 'sites';
    }

    public static function label(): string
    {
        return 'Sites';
    }

    public static function stage(): int
    {
        return 20;
    }

    public static function icon(): string
    {
        return 'world';
    }

    public function all(): array
    {
        return Craft::$app->getSites()->getAllSites(true);
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getSites()->getSiteByHandle($handle, true);
    }

    public function dump(object $component): array
    {
        /** @var Site $component */
        $dump = $this->read($component, self::ATTRIBUTES);

        // Read these unparsed: a site's name and base URL are usually environment
        // variables, and exporting the resolved value would bake one environment's
        // configuration into a blueprint meant for all of them.
        $dump['name'] = $component->getName(false);
        $dump['language'] = $component->getLanguage(false);
        $dump['baseUrl'] = $component->getBaseUrl(false);

        $group = Craft::$app->getSites()->getGroupById($component->groupId);
        $dump['group'] = $group?->getName(false);

        return $dump;
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        $where = 'site “' . ($spec['handle'] ?: '?') . '”';

        if (($spec['language'] ?? '') === '') {
            $report->error('No language. Add `language: en-US` or similar.', $where);
        }

        if ($this->find((string)($spec['handle'] ?? '')) === null) {
            $report->warn(
                'This site does not exist yet. Creating it will propagate every existing entry into it, which on a large site is a long job and not one that undoes cleanly.',
                $where
            );
        }

        if (isset($spec['group'])) {
            $names = array_map(fn($group) => $group->getName(false), Craft::$app->getSites()->getAllGroups());
            $declared = array_column($blueprint->specs('siteGroups'), 'name');
            if (!in_array($spec['group'], $names, true) && !in_array($spec['group'], $declared, true)) {
                $report->error(sprintf('The site group “%s” does not exist and is not created by this blueprint.', $spec['group']), $where);
            }
        }
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $site = $existing instanceof Site ? $existing : new Site();

        $this->assign($site, $spec, self::ATTRIBUTES);

        foreach (self::RAW_ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $spec)) {
                $site->$attribute = $spec[$attribute];
            }
        }

        if (isset($spec['group'])) {
            foreach (Craft::$app->getSites()->getAllGroups() as $group) {
                if ($group->getName(false) === $spec['group']) {
                    $site->groupId = $group->id;
                    break;
                }
            }
        }

        if ($site->groupId === null) {
            $groups = Craft::$app->getSites()->getAllGroups();
            $site->groupId = $groups[0]->id ?? null;
        }

        if (!Craft::$app->getSites()->saveSite($site)) {
            throw $this->failed($site, "the site “{$spec['handle']}”");
        }

        return $site;
    }

    /**
     * Deleting a site deletes every entry that only existed there. Archie will not do
     * that on the strength of a blueprint; it has to be done deliberately in the CP.
     */
    public function delete(object $component): bool
    {
        throw new \RuntimeException(
            'Archie will not delete a site. Deleting a site removes its content, which no blueprint should be able to do as a side effect. Delete it in Settings → Sites.'
        );
    }
}
