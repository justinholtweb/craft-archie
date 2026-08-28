<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\base\FsInterface;
use craft\fs\MissingFs;
use justinholtweb\archie\helpers\TypeResolver;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Filesystems.
 *
 * Credentials do not belong in a blueprint, so anything that looks like one is written
 * out as an environment variable reference rather than its value.
 */
class FilesystemHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['name', 'handle', 'hasUrls', 'url'];

    /** Setting names that hold something nobody should commit to a repository. */
    private const SECRET_HINTS = ['key', 'secret', 'password', 'token', 'credential'];

    public static function type(): string
    {
        return 'filesystems';
    }

    public static function label(): string
    {
        return 'Filesystems';
    }

    public static function stage(): int
    {
        return 30;
    }

    public static function icon(): string
    {
        return 'folder-open';
    }

    public function all(): array
    {
        return Craft::$app->getFs()->getAllFilesystems();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getFs()->getFilesystemByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var FsInterface $component */
        $dump = $this->read($component, self::ATTRIBUTES);
        $dump['type'] = $component::class;
        $dump['settings'] = $component->getSettings();

        return $dump;
    }

    public function normalize(array $spec): array
    {
        $spec = parent::normalize($spec);

        $type = (string)($spec['type'] ?? '');
        $spec['type'] = TypeResolver::resolve($type, $this->availableTypes()) ?? $type;
        $spec['settings'] = is_array($spec['settings'] ?? null) ? $spec['settings'] : [];

        return $spec;
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        $where = 'filesystem “' . ($spec['handle'] ?: '?') . '”';

        if (($spec['type'] ?? '') === '') {
            $report->error('No filesystem type. Add a `type`, e.g. `local`.', $where);
            return;
        }

        if (TypeResolver::resolve($spec['type'], $this->availableTypes()) === null) {
            $report->error(
                sprintf('Unknown filesystem type “%s”. Is the plugin that provides it installed?', $spec['type']),
                $where
            );
            return;
        }

        foreach ($spec['settings'] as $key => $value) {
            if (!is_string($value) || $value === '' || str_starts_with($value, '$')) {
                continue;
            }
            foreach (self::SECRET_HINTS as $hint) {
                if (stripos($key, $hint) !== false) {
                    $report->warn(
                        sprintf('`settings.%s` holds a literal value. Use an environment variable (`$MY_VAR`) instead of committing a credential.', $key),
                        $where
                    );
                    break;
                }
            }
        }
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $config = ['type' => $spec['type'], 'settings' => $spec['settings']];

        foreach (self::ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $spec)) {
                $config[$attribute] = $spec[$attribute];
            }
        }

        if ($existing !== null) {
            $config['uid'] = $existing->uid ?? null;
            $config['oldHandle'] = $existing->handle;
        }

        $fs = Craft::$app->getFs()->createFilesystem($config);

        if ($fs instanceof MissingFs) {
            throw new \RuntimeException(sprintf('Craft could not create a “%s” filesystem.', $spec['type']));
        }

        if (!Craft::$app->getFs()->saveFilesystem($fs)) {
            throw $this->failed($fs, "the filesystem “{$spec['handle']}”");
        }

        return $fs;
    }

    public function delete(object $component): bool
    {
        /** @var FsInterface $component */
        return Craft::$app->getFs()->removeFilesystem($component);
    }

    /** @return string[] */
    private function availableTypes(): array
    {
        return Craft::$app->getFs()->getAllFilesystemTypes();
    }
}
