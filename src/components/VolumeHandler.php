<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use justinholtweb\archie\helpers\FieldLayoutHelper;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Asset volumes. A volume is the content-model half of asset storage; the filesystem it
 * sits on is the infrastructure half, and gets its own handler.
 */
class VolumeHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = [
        'name', 'handle', 'subpath', 'transformSubpath',
        'titleTranslationMethod', 'titleTranslationKeyFormat',
        'altTranslationMethod', 'altTranslationKeyFormat', 'sortOrder',
    ];

    public static function type(): string
    {
        return 'volumes';
    }

    public static function label(): string
    {
        return 'Volumes';
    }

    public static function stage(): int
    {
        return 80;
    }

    public static function icon(): string
    {
        return 'photo';
    }

    public function all(): array
    {
        return Craft::$app->getVolumes()->getAllVolumes();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getVolumes()->getVolumeByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var Volume $component */
        $dump = $this->read($component, self::ATTRIBUTES);
        $dump['fs'] = $component->getFsHandle();
        $dump['transformFs'] = $component->getTransformFsHandle(false);
        $dump['fieldLayout'] = FieldLayoutHelper::dump($component->getFieldLayout());

        return $dump;
    }

    public function normalize(array $spec): array
    {
        $spec = parent::normalize($spec);

        // Craft's own Volume config accepts either spelling; blueprints use the short one.
        foreach (['fsHandle' => 'fs', 'transformFsHandle' => 'transformFs'] as $long => $short) {
            if (isset($spec[$long]) && !isset($spec[$short])) {
                $spec[$short] = $spec[$long];
            }
            unset($spec[$long]);
        }

        return $spec;
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        $where = 'volume “' . ($spec['handle'] ?: '?') . '”';
        $fs = $spec['fs'] ?? null;

        if ($fs === null || $fs === '') {
            $report->error('No filesystem. Add `fs: <filesystem handle>`.', $where);
            return;
        }

        $declared = array_column($blueprint->specs('filesystems'), 'handle');
        foreach (array_filter([$fs, $spec['transformFs'] ?? null]) as $handle) {
            if (in_array($handle, $declared, true)) {
                continue;
            }
            if (Craft::$app->getFs()->getFilesystemByHandle((string)$handle) === null) {
                $report->error(
                    sprintf('The filesystem “%s” does not exist and is not created by this blueprint.', $handle),
                    $where
                );
            }
        }
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $volume = $existing instanceof Volume ? $existing : new Volume();

        $this->assign($volume, $spec, self::ATTRIBUTES);

        if (array_key_exists('fs', $spec)) {
            $volume->setFsHandle((string)$spec['fs']);
        }
        if (array_key_exists('transformFs', $spec)) {
            $volume->setTransformFsHandle($spec['transformFs'] !== null ? (string)$spec['transformFs'] : null);
        }

        if (isset($spec['fieldLayout'])) {
            $missing = [];
            $layout = FieldLayoutHelper::build($spec['fieldLayout'], Asset::class, $missing);
            if ($missing !== []) {
                $context->warn(sprintf(
                    'Volume “%s”: %s left out of the field layout — not found.',
                    $spec['handle'],
                    implode(', ', array_map(fn($h) => "“{$h}”", $missing))
                ));
            }
            $layout->id = $volume->getFieldLayout()->id;
            $layout->uid = $volume->getFieldLayout()->uid;
            $volume->setFieldLayout($layout);
        }

        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            throw $this->failed($volume, "the volume “{$spec['handle']}”");
        }

        return $volume;
    }

    public function delete(object $component): bool
    {
        /** @var Volume $component */
        return Craft::$app->getVolumes()->deleteVolume($component);
    }
}
