<?php

namespace justinholtweb\archie\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\archie\Plugin;
use yii\console\ExitCode;

/**
 * List and undo previous applies.
 */
class HistoryController extends Controller
{
    public $defaultAction = 'index';

    public function options($actionID): array
    {
        return $actionID === 'rollback'
            ? array_merge(parent::options($actionID), ['interactive'])
            : parent::options($actionID);
    }

    /**
     * Lists recent applies, newest first.
     */
    public function actionIndex(): int
    {
        $runs = Plugin::getInstance()->snapshots->all();

        if ($runs === []) {
            $this->stdout('No blueprints have been applied yet.' . PHP_EOL);
            return ExitCode::OK;
        }

        $this->stdout(str_pad('ID', 6) . str_pad('WHEN', 22) . str_pad('BLUEPRINT', 32) . str_pad('ITEMS', 8) . 'STATUS' . PHP_EOL, Console::BOLD);

        foreach ($runs as $run) {
            $summary = Json::decodeIfJson((string)$run->summary) ?: [];
            $failed = !empty($summary['error']);

            $this->stdout(str_pad((string)$run->id, 6));
            $this->stdout(str_pad((string)$run->dateCreated, 22));
            $this->stdout(str_pad(substr((string)$run->blueprintName, 0, 30), 32));
            $this->stdout(str_pad((string)$run->itemCount, 8));

            if ($run->rolledBack) {
                $this->stdout('rolled back' . PHP_EOL, Console::FG_GREY);
            } elseif ($failed) {
                $this->stdout('failed part-way' . PHP_EOL, Console::FG_RED);
            } else {
                $this->stdout('applied' . PHP_EOL, Console::FG_GREEN);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Shows what one apply did.
     */
    public function actionShow(int $id): int
    {
        $run = Plugin::getInstance()->snapshots->get($id);

        if ($run === null) {
            $this->stderr("There is no run $id." . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout(PHP_EOL . $run->blueprintName . ' — run ' . $run->id . PHP_EOL, Console::BOLD);
        $this->stdout('Applied ' . $run->dateCreated . PHP_EOL . PHP_EOL);

        foreach (Plugin::getInstance()->snapshots->items($id) as $item) {
            $this->stdout('  ' . str_pad($item->action, 10), $item->action === 'create' ? Console::FG_GREEN : Console::FG_YELLOW);
            $this->stdout(str_pad($item->type, 18) . $item->handle . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Undoes an apply: deletes what it created and restores what it changed.
     */
    public function actionRollback(int $id): int
    {
        $snapshots = Plugin::getInstance()->snapshots;
        $run = $snapshots->get($id);

        if ($run === null) {
            $this->stderr("There is no run $id." . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if ($run->rolledBack) {
            $this->stderr("Run $id has already been rolled back." . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::DATAERR;
        }

        $steps = $snapshots->describeRollback($id);
        $destructive = array_filter($steps, fn(array $step) => $step['destructive']);

        $this->stdout(PHP_EOL . 'Rolling back run ' . $id . ' (' . $run->blueprintName . ') will:' . PHP_EOL . PHP_EOL);

        foreach ($steps as $step) {
            $this->stdout('  • ' . $step['label'] . PHP_EOL, $step['destructive'] ? Console::FG_RED : null);
        }

        if ($destructive !== []) {
            $this->stdout(PHP_EOL . sprintf(
                '%d of these delete something that holds content. Any entries, categories or assets in them go too, and this does not bring them back.' . PHP_EOL,
                count($destructive)
            ), Console::FG_RED);
        }

        if ($this->interactive && !$this->confirm(PHP_EOL . 'Roll back?', false)) {
            $this->stdout('Nothing was changed.' . PHP_EOL);
            return ExitCode::OK;
        }

        $result = $snapshots->rollback($id);

        foreach ($result->warnings as $warning) {
            $this->stdout('  warning  ', Console::FG_YELLOW);
            $this->stdout($warning . PHP_EOL);
        }

        if (!$result->isSuccess()) {
            $this->stderr(PHP_EOL . 'Failed on ' . $result->failedOn . ': ' . $result->error . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(PHP_EOL . sprintf('Reverted %d component(s).', $result->count()) . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes old run records, keeping the number set in the plugin's settings.
     */
    public function actionPrune(): int
    {
        Plugin::getInstance()->snapshots->prune();
        $this->stdout('Pruned.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
