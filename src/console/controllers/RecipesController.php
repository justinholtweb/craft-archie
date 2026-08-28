<?php

namespace justinholtweb\archie\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use justinholtweb\archie\Plugin;
use yii\console\ExitCode;

/**
 * The blueprints that ship with Archie.
 */
class RecipesController extends Controller
{
    public $defaultAction = 'index';

    /** Where to copy the recipe to. Defaults to the blueprint directory. */
    public string $out = '';

    public function options($actionID): array
    {
        return $actionID === 'copy'
            ? array_merge(parent::options($actionID), ['out'])
            : parent::options($actionID);
    }

    /**
     * Lists the bundled recipes.
     */
    public function actionIndex(): int
    {
        $recipes = Plugin::getInstance()->recipes->all();

        foreach ($recipes as $recipe) {
            $this->stdout('  ' . str_pad($recipe['handle'], 18), Console::FG_CYAN);
            $this->stdout($recipe['name'] . PHP_EOL, Console::BOLD);

            if ($recipe['description'] !== '') {
                $this->stdout('    ' . wordwrap($recipe['description'], 90, PHP_EOL . '    ') . PHP_EOL, Console::FG_GREY);
            }

            $parts = [];
            foreach ($recipe['summary'] as $type => $count) {
                $parts[] = "$count $type";
            }
            $this->stdout('    ' . implode(', ', $parts) . PHP_EOL . PHP_EOL, Console::FG_GREY);
        }

        $this->stdout('Plan one with: craft archie/blueprint/plan <handle>' . PHP_EOL);
        $this->stdout('Copy one into your project with: craft archie/recipes/copy <handle>' . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Prints a recipe.
     */
    public function actionShow(string $handle): int
    {
        try {
            $this->stdout(Plugin::getInstance()->recipes->read($handle));
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        return ExitCode::OK;
    }

    /**
     * Copies a recipe into the project so it can be edited and committed.
     *
     * This is the intended way to use one: a recipe inside the plugin is a starting
     * point, and a blueprint you have edited is the thing your project actually keeps.
     */
    public function actionCopy(string $handle): int
    {
        $recipes = Plugin::getInstance()->recipes;
        $blueprints = Plugin::getInstance()->blueprints;

        try {
            $contents = $recipes->read($handle);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if ($this->out !== '') {
            FileHelper::writeToFile($this->out, $contents);
            $path = $this->out;
        } else {
            $path = $blueprints->write("$handle.yaml", $contents);
        }

        $this->stdout("Copied to $path" . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
