<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\models\ImageTransform;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Named image transforms.
 */
class TransformHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['name', 'handle', 'mode', 'width', 'height', 'position', 'interlace', 'quality', 'format', 'fill', 'upscale'];

    public static function type(): string
    {
        return 'transforms';
    }

    public static function label(): string
    {
        return 'Image transforms';
    }

    public static function singular(): string
    {
        return 'image transform';
    }

    public static function stage(): int
    {
        return 40;
    }

    public static function icon(): string
    {
        return 'crop';
    }

    public function all(): array
    {
        return Craft::$app->getImageTransforms()->getAllTransforms();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getImageTransforms()->getTransformByHandle($handle);
    }

    public function dump(object $component): array
    {
        return $this->read($component, self::ATTRIBUTES);
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        $where = 'transform “' . ($spec['handle'] ?: '?') . '”';
        $modes = ['crop', 'fit', 'stretch', 'letterbox'];

        if (isset($spec['mode']) && !in_array($spec['mode'], $modes, true)) {
            $report->error(sprintf('“%s” is not a transform mode. Use one of: %s.', $spec['mode'], implode(', ', $modes)), $where);
        }

        if (($spec['mode'] ?? 'crop') === 'crop' && empty($spec['width']) && empty($spec['height'])) {
            $report->warn('Neither a width nor a height was given, so this transform will not resize anything.', $where);
        }
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $transform = $existing instanceof ImageTransform ? $existing : new ImageTransform();

        $this->assign($transform, $spec, self::ATTRIBUTES);

        if (!Craft::$app->getImageTransforms()->saveTransform($transform)) {
            throw $this->failed($transform, "the transform “{$spec['handle']}”");
        }

        return $transform;
    }

    public function delete(object $component): bool
    {
        /** @var ImageTransform $component */
        return Craft::$app->getImageTransforms()->deleteTransform($component);
    }
}
