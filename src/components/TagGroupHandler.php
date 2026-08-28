<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\elements\Tag;
use craft\models\TagGroup;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\models\ApplyContext;

/**
 * Tag groups.
 */
class TagGroupHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['name', 'handle'];

    public static function type(): string
    {
        return 'tagGroups';
    }

    public static function label(): string
    {
        return 'Tag groups';
    }

    public static function singular(): string
    {
        return 'tag group';
    }

    public static function stage(): int
    {
        return 100;
    }

    public static function icon(): string
    {
        return 'tags';
    }

    public function all(): array
    {
        return Craft::$app->getTags()->getAllTagGroups();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getTags()->getTagGroupByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var TagGroup $component */
        $dump = $this->read($component, self::ATTRIBUTES);
        $dump['fieldLayout'] = FieldLayoutHelper::dump($component->getFieldLayout());

        return $dump;
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $group = $existing instanceof TagGroup ? $existing : new TagGroup();

        $this->assign($group, $spec, self::ATTRIBUTES);

        if (isset($spec['fieldLayout'])) {
            $missing = [];
            $layout = FieldLayoutHelper::build($spec['fieldLayout'], Tag::class, $missing);
            if ($missing !== []) {
                $context->warn(sprintf(
                    'Tag group “%s”: %s left out of the field layout — not found.',
                    $spec['handle'],
                    implode(', ', array_map(fn($h) => "“{$h}”", $missing))
                ));
            }
            $layout->id = $group->getFieldLayout()->id;
            $layout->uid = $group->getFieldLayout()->uid;
            $group->setFieldLayout($layout);
        }

        if (!Craft::$app->getTags()->saveTagGroup($group)) {
            throw $this->failed($group, "the tag group “{$spec['handle']}”");
        }

        return $group;
    }

    public function delete(object $component): bool
    {
        /** @var TagGroup $component */
        return Craft::$app->getTags()->deleteTagGroup($component);
    }
}
