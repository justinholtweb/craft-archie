<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\elements\GlobalSet;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\models\ApplyContext;

/**
 * Global sets.
 *
 * A global set is an element rather than a settings model, so it is found by handle
 * through the globals service and saved the same way.
 */
class GlobalSetHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['name', 'handle', 'sortOrder'];

    public static function type(): string
    {
        return 'globalSets';
    }

    public static function label(): string
    {
        return 'Global sets';
    }

    public static function singular(): string
    {
        return 'global set';
    }

    public static function stage(): int
    {
        return 110;
    }

    public static function icon(): string
    {
        return 'globe';
    }

    public function all(): array
    {
        return Craft::$app->getGlobals()->getAllSets();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getGlobals()->getSetByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var GlobalSet $component */
        $dump = $this->read($component, self::ATTRIBUTES);
        $dump['fieldLayout'] = FieldLayoutHelper::dump($component->getFieldLayout());

        return $dump;
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $set = $existing instanceof GlobalSet ? $existing : new GlobalSet();

        $this->assign($set, $spec, self::ATTRIBUTES);

        if (isset($spec['fieldLayout'])) {
            $missing = [];
            $layout = FieldLayoutHelper::build($spec['fieldLayout'], GlobalSet::class, $missing);
            if ($missing !== []) {
                $context->warn(sprintf(
                    'Global set “%s”: %s left out of the field layout — not found.',
                    $spec['handle'],
                    implode(', ', array_map(fn($h) => "“{$h}”", $missing))
                ));
            }
            $layout->id = $set->getFieldLayout()->id;
            $layout->uid = $set->getFieldLayout()->uid;
            $set->setFieldLayout($layout);
        }

        if (!Craft::$app->getGlobals()->saveSet($set)) {
            throw $this->failed($set, "the global set “{$spec['handle']}”");
        }

        return $set;
    }

    public function delete(object $component): bool
    {
        /** @var GlobalSet $component */
        Craft::$app->getGlobals()->deleteSet($component);
        return true;
    }
}
