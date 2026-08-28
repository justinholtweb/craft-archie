<?php

namespace justinholtweb\archie\services;

use craft\base\Component;
use craft\helpers\FileHelper;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\models\Blueprint;
use yii\base\InvalidArgumentException;

/**
 * The blueprints that ship with Archie.
 *
 * A recipe is an ordinary blueprint that happens to live inside the plugin. It is meant
 * to be planned, read, and then copied into the project and edited — a starting point,
 * not a dependency.
 */
class Recipes extends Component
{
    /** @var array<int, array{handle: string, name: string, description: string, path: string, summary: array}>|null */
    private ?array $recipes = null;

    public function directory(): string
    {
        return FileHelper::normalizePath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'recipes');
    }

    /**
     * @return array<int, array{handle: string, name: string, description: string, path: string, summary: array}>
     */
    public function all(): array
    {
        if ($this->recipes !== null) {
            return $this->recipes;
        }

        $directory = $this->directory();
        $recipes = [];

        if (is_dir($directory)) {
            foreach (FileHelper::findFiles($directory, ['only' => ['*.yaml'], 'recursive' => false]) as $path) {
                $handle = pathinfo($path, PATHINFO_FILENAME);

                try {
                    $blueprint = Plugin::getInstance()->blueprints->parse((string)file_get_contents($path), [], $path);
                } catch (\Throwable) {
                    continue;
                }

                $recipes[] = [
                    'handle' => $handle,
                    'name' => $blueprint->name !== '' ? $blueprint->name : ucfirst($handle),
                    'description' => $blueprint->description,
                    'path' => $path,
                    'summary' => array_map('count', $blueprint->components),
                ];
            }
        }

        usort($recipes, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $this->recipes = $recipes;
    }

    /** @return array{handle: string, name: string, description: string, path: string, summary: array}|null */
    public function get(string $handle): ?array
    {
        foreach ($this->all() as $recipe) {
            if ($recipe['handle'] === $handle) {
                return $recipe;
            }
        }

        return null;
    }

    public function read(string $handle): string
    {
        $recipe = $this->get($handle);

        if ($recipe === null) {
            throw new InvalidArgumentException(sprintf('There is no recipe called “%s”.', $handle));
        }

        return (string)file_get_contents($recipe['path']);
    }

    public function open(string $handle, array $vars = []): Blueprint
    {
        return Plugin::getInstance()->blueprints->parse($this->read($handle), $vars, $this->get($handle)['path']);
    }
}
