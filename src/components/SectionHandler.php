<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Sections, and the per-site URL settings that make them addressable.
 */
class SectionHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = [
        'name', 'handle', 'type', 'enableVersioning', 'propagationMethod',
        'defaultPlacement', 'maxLevels', 'previewTargets',
    ];

    /** The per-site keys a blueprint may set, and the defaults for a site it does not mention. */
    private const SITE_SETTING_ATTRIBUTES = ['enabledByDefault', 'hasUrls', 'uriFormat', 'template'];

    public static function type(): string
    {
        return 'sections';
    }

    public static function label(): string
    {
        return 'Sections';
    }

    public static function stage(): int
    {
        return 70;
    }

    public static function icon(): string
    {
        return 'newspaper';
    }

    public function all(): array
    {
        return Craft::$app->getEntries()->getAllSections();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getEntries()->getSectionByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var Section $component */
        $dump = $this->read($component, self::ATTRIBUTES);

        $dump['entryTypes'] = array_map(
            fn($entryType) => $entryType->handle,
            $component->getEntryTypes()
        );

        $siteSettings = [];
        foreach ($component->getSiteSettings() as $settings) {
            $site = Craft::$app->getSites()->getSiteById($settings->siteId);
            if ($site === null) {
                continue;
            }
            $siteSettings[$site->handle] = $this->read($settings, self::SITE_SETTING_ATTRIBUTES);
        }
        $dump['siteSettings'] = $siteSettings;

        return $dump;
    }

    public function normalize(array $spec): array
    {
        $spec = parent::normalize($spec);
        $spec['type'] = strtolower((string)($spec['type'] ?? Section::TYPE_CHANNEL));

        // A single entry type may be written as a bare string.
        if (isset($spec['entryTypes']) && is_string($spec['entryTypes'])) {
            $spec['entryTypes'] = [$spec['entryTypes']];
        }

        // `uriFormat` and `template` at the top level are shorthand for "on every site",
        // which is what a single-site project always means.
        return $this->expandSiteSettings($spec, self::SITE_SETTING_ATTRIBUTES);
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        $where = 'section “' . ($spec['handle'] ?: '?') . '”';
        $types = [Section::TYPE_SINGLE, Section::TYPE_CHANNEL, Section::TYPE_STRUCTURE];

        if (!in_array($spec['type'] ?? '', $types, true)) {
            $report->error(
                sprintf('“%s” is not a section type. Use one of: %s.', $spec['type'] ?? '', implode(', ', $types)),
                $where
            );
        }

        $entryTypes = $spec['entryTypes'] ?? [];

        if ($entryTypes === []) {
            $report->error('A section needs at least one entry type.', $where);
        }

        $declared = array_column($blueprint->specs('entryTypes'), 'handle');
        foreach ($entryTypes as $handle) {
            if (in_array($handle, $declared, true)) {
                continue;
            }
            if (Craft::$app->getEntries()->getEntryTypeByHandle((string)$handle) !== null) {
                continue;
            }
            $report->error(
                sprintf('References the entry type “%s”, which does not exist and is not created by this blueprint.', $handle),
                $where
            );
        }

        if (($spec['type'] ?? '') === Section::TYPE_SINGLE && count($entryTypes) > 1) {
            $report->error('A single can only have one entry type.', $where);
        }

        foreach (array_keys($spec['siteSettings'] ?? []) as $siteHandle) {
            if ($siteHandle === '*') {
                continue;
            }
            $declaredSites = array_column($blueprint->specs('sites'), 'handle');
            if (in_array($siteHandle, $declaredSites, true)) {
                continue;
            }
            if (Craft::$app->getSites()->getSiteByHandle((string)$siteHandle) === null) {
                $report->error(sprintf('Site settings are given for “%s”, which is not a site on this install.', $siteHandle), $where);
            }
        }
    }

    /**
     * Converting a structure to anything else throws away the hierarchy, and converting
     * a channel to a single throws away every entry but one.
     */
    public function conflict(array $spec, object $existing): ?string
    {
        /** @var Section $existing */
        if (!isset($spec['type']) || $existing->type === $spec['type']) {
            return null;
        }

        if ($existing->type === Section::TYPE_STRUCTURE) {
            return sprintf('Already exists as a structure. Changing it to a %s discards the entry hierarchy.', $spec['type']);
        }

        if ($spec['type'] === Section::TYPE_SINGLE) {
            return sprintf('Already exists as a %s. Changing it to a single deletes all but one of its entries.', $existing->type);
        }

        return null;
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $section = $existing instanceof Section ? $existing : new Section();

        $this->assign($section, $spec, self::ATTRIBUTES);

        if (isset($spec['entryTypes'])) {
            $entryTypes = [];
            foreach ($spec['entryTypes'] as $handle) {
                $entryType = Craft::$app->getEntries()->getEntryTypeByHandle((string)$handle);
                if ($entryType === null) {
                    throw new \RuntimeException(sprintf(
                        'The section “%s” needs the entry type “%s”, which does not exist.',
                        $spec['handle'],
                        $handle
                    ));
                }
                $entryTypes[] = $entryType;
            }
            $section->setEntryTypes($entryTypes);
        }

        if (isset($spec['siteSettings'])) {
            $section->setSiteSettings($this->buildSiteSettings($spec, $section));
        }

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw $this->failed($section, "the section “{$spec['handle']}”");
        }

        return $section;
    }

    /**
     * Builds the per-site settings, starting from whatever the section already has so an
     * update that mentions one site does not wipe the others.
     *
     * @return Section_SiteSettings[] keyed by site ID
     */
    private function buildSiteSettings(array $spec, Section $section): array
    {
        $existing = [];
        foreach ($section->getSiteSettings() as $settings) {
            $existing[$settings->siteId] = $settings;
        }

        $wildcard = $spec['siteSettings']['*'] ?? null;
        $all = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $stated = $spec['siteSettings'][$site->handle] ?? null;

            if ($stated === null && $wildcard === null) {
                // Not mentioned. Keep what is there; skip the site entirely if the
                // section was never enabled for it.
                if (isset($existing[$site->id])) {
                    $all[$site->id] = $existing[$site->id];
                }
                continue;
            }

            if ($stated === false) {
                // An explicit `false` turns the section off for this site.
                continue;
            }

            $settings = $existing[$site->id] ?? new Section_SiteSettings(['siteId' => $site->id]);
            $merged = array_merge(is_array($wildcard) ? $wildcard : [], is_array($stated) ? $stated : []);

            $this->assign($settings, $merged, self::SITE_SETTING_ATTRIBUTES);

            // Having a URI format is what "has URLs" means; making the author say both
            // is a way of letting them disagree.
            if (!array_key_exists('hasUrls', $merged) && array_key_exists('uriFormat', $merged)) {
                $settings->hasUrls = (string)$merged['uriFormat'] !== '';
            }

            $all[$site->id] = $settings;
        }

        return $all;
    }

    public function delete(object $component): bool
    {
        /** @var Section $component */
        return Craft::$app->getEntries()->deleteSection($component);
    }
}
