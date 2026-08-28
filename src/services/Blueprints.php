<?php

namespace justinholtweb\archie\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\blueprints\Dumper;
use justinholtweb\archie\blueprints\Parser;
use justinholtweb\archie\models\Blueprint;
use yii\base\InvalidArgumentException;

/**
 * Blueprints on disk.
 *
 * They live in the project, not the database, because a content model belongs in version
 * control beside the project config it produces. The database only ever holds the record
 * of what was applied.
 */
class Blueprints extends Component
{
    private const EXTENSIONS = ['yaml', 'yml', 'json'];

    /** The absolute path to the blueprint directory, whether or not it exists yet. */
    public function directory(): string
    {
        $path = Plugin::getInstance()->getSettings()->blueprintPath;

        return FileHelper::normalizePath(Craft::getAlias('@root') . DIRECTORY_SEPARATOR . $path);
    }

    /**
     * @return array<int, array{name: string, path: string, format: string, size: int, modified: int}>
     */
    public function all(): array
    {
        $directory = $this->directory();

        if (!is_dir($directory)) {
            return [];
        }

        $blueprints = [];

        foreach (FileHelper::findFiles($directory, ['only' => ['*.yaml', '*.yml', '*.json'], 'recursive' => false]) as $path) {
            $blueprints[] = [
                'name' => basename($path),
                'path' => $path,
                'format' => pathinfo($path, PATHINFO_EXTENSION) === 'json' ? 'json' : 'yaml',
                'size' => (int)filesize($path),
                'modified' => (int)filemtime($path),
            ];
        }

        usort($blueprints, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $blueprints;
    }

    /**
     * Resolves a blueprint name to a path inside the blueprint directory.
     *
     * Names arrive from the CP and the command line, so this refuses anything that is not
     * a plain filename with a known extension — no directories, no traversal, no writing
     * a `.php` file into the project because it was asked nicely.
     */
    public function path(string $name): string
    {
        $name = basename(trim($name));

        if ($name === '' || str_starts_with($name, '.')) {
            throw new InvalidArgumentException('That is not a usable blueprint name.');
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'A blueprint has to be one of: %s.',
                implode(', ', array_map(fn($e) => ".$e", self::EXTENSIONS))
            ));
        }

        return $this->directory() . DIRECTORY_SEPARATOR . $name;
    }

    public function read(string $name): string
    {
        $path = $this->path($name);

        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('There is no blueprint called “%s”.', basename($name)));
        }

        return (string)file_get_contents($path);
    }

    public function write(string $name, string $contents): string
    {
        $path = $this->path($name);

        FileHelper::createDirectory(dirname($path));
        FileHelper::writeToFile($path, $contents);

        return $path;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);

        if (!is_file($path)) {
            return false;
        }

        FileHelper::unlink($path);

        return true;
    }

    /**
     * Reads and parses a blueprint from the blueprint directory.
     *
     * Not called `load()`: Yii's Model already defines that with a different signature,
     * and overriding it is a fatal at compile time.
     */
    public function open(string $name, array $vars = []): Blueprint
    {
        return $this->parse($this->read($name), $vars, $this->path($name));
    }

    /** Reads and parses a blueprint from anywhere on disk, for the console. */
    public function openFile(string $path, array $vars = []): Blueprint
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('There is no file at %s.', $path));
        }

        return $this->parse((string)file_get_contents($path), $vars, $path);
    }

    public function parse(string $contents, array $vars = [], ?string $source = null): Blueprint
    {
        return (new Parser())->parse($contents, $vars, $source);
    }

    public function dump(Blueprint $blueprint, ?string $format = null): string
    {
        return (new Dumper())->dump($blueprint, $format);
    }
}
