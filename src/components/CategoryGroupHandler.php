<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\elements\Category;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\models\ApplyContext;

/**
 * Category groups.
 */
class CategoryGroupHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['name', 'handle', 'maxLevels', 'defaultPlacement'];

    private const SITE_SETTING_ATTRIBUTES = ['hasUrls', 'uriFormat', 'template'];

    public static function type(): string
    {
        return 'categoryGroups';
    }

    public static function label(): string
    {
        return 'Category groups';
    }

    public static function singular(): string
    {
        return 'category group';
    }

    public static function stage(): int
    {
        return 90;
    }

    public static function icon(): string
    {
        return 'folder-tree';
    }

    public function all(): array
    {
        return Craft::$app->getCategories()->getAllGroups();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getCategories()->getGroupByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var CategoryGroup $component */
        $dump = $this->read($component, self::ATTRIBUTES);
        $dump['fieldLayout'] = FieldLayoutHelper::dump($component->getFieldLayout());

        $siteSettings = [];
        foreach ($component->getSiteSettings() as $settings) {
            $site = Craft::$app->getSites()->getSiteById($settings->siteId);
            if ($site !== null) {
                $siteSettings[$site->handle] = $this->read($settings, self::SITE_SETTING_ATTRIBUTES);
            }
        }
        $dump['siteSettings'] = $siteSettings;

        return $dump;
    }

    public function normalize(array $spec): array
    {
        $spec = parent::normalize($spec);

        return $this->expandSiteSettings($spec, self::SITE_SETTING_ATTRIBUTES);
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $group = $existing instanceof CategoryGroup ? $existing : new CategoryGroup();

        $this->assign($group, $spec, self::ATTRIBUTES);

        if (isset($spec['fieldLayout'])) {
            $missing = [];
            $layout = FieldLayoutHelper::build($spec['fieldLayout'], Category::class, $missing);
            if ($missing !== []) {
                $context->warn(sprintf(
                    'Category group “%s”: %s left out of the field layout — not found.',
                    $spec['handle'],
                    implode(', ', array_map(fn($h) => "“{$h}”", $missing))
                ));
            }
            $layout->id = $group->getFieldLayout()->id;
            $layout->uid = $group->getFieldLayout()->uid;
            $group->setFieldLayout($layout);
        }

        $group->setSiteSettings($this->buildSiteSettings($spec, $group));

        if (!Craft::$app->getCategories()->saveGroup($group)) {
            throw $this->failed($group, "the category group “{$spec['handle']}”");
        }

        return $group;
    }

    /** @return CategoryGroup_SiteSettings[] keyed by site ID */
    private function buildSiteSettings(array $spec, CategoryGroup $group): array
    {
        $existing = [];
        foreach ($group->getSiteSettings() as $settings) {
            $existing[$settings->siteId] = $settings;
        }

        $wildcard = $spec['siteSettings']['*'] ?? null;
        $all = [];

        // Unlike a section, a category group has to be enabled for every site, so this
        // always returns a row per site rather than only the ones that were mentioned.
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $settings = $existing[$site->id] ?? new CategoryGroup_SiteSettings(['siteId' => $site->id]);
            $stated = $spec['siteSettings'][$site->handle] ?? null;
            $merged = array_merge(is_array($wildcard) ? $wildcard : [], is_array($stated) ? $stated : []);

            if ($merged !== []) {
                $this->assign($settings, $merged, self::SITE_SETTING_ATTRIBUTES);
                if (!array_key_exists('hasUrls', $merged) && array_key_exists('uriFormat', $merged)) {
                    $settings->hasUrls = (string)$merged['uriFormat'] !== '';
                }
            }

            $all[$site->id] = $settings;
        }

        return $all;
    }

    public function delete(object $component): bool
    {
        /** @var CategoryGroup $component */
        return Craft::$app->getCategories()->deleteGroup($component);
    }
}
