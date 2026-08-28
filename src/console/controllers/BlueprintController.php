<?php

namespace justinholtweb\archie\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\helpers\Differ;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\Plan;
use justinholtweb\archie\models\PlanItem;
use yii\console\ExitCode;

/**
 * Lint, plan, apply and export content model blueprints.
 */
class BlueprintController extends Controller
{
    public $defaultAction = 'plan';

    /** Values for the blueprint's `{{ placeholders }}`, as `name=value,other=value`. */
    public string $vars = '';

    /** Limit the run to these component types, comma-separated (e.g. `fields,sections`). */
    public string $types = '';

    /** Allow changes that discard content, such as changing a field's type. */
    public bool $force = false;

    /** Delete live components of a declared type that the blueprint does not mention. */
    public bool $prune = false;

    /** Component types to export, comma-separated. Defaults to everything. */
    public string $only = '';

    /** Handles to export, comma-separated. Defaults to everything of the chosen types. */
    public string $handles = '';

    /** Where to write the output. Prints to stdout when omitted. */
    public string $out = '';

    /** Output format: `yaml` or `json`. */
    public string $format = '';

    /** Leave out the components the selection depends on. */
    public bool $bare = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'lint' => array_merge($options, ['vars']),
            'plan' => array_merge($options, ['vars', 'types', 'force', 'prune']),
            'apply' => array_merge($options, ['vars', 'types', 'force', 'prune', 'interactive']),
            'export' => array_merge($options, ['only', 'handles', 'out', 'format', 'bare']),
            'convert' => array_merge($options, ['out', 'format']),
            default => $options,
        };
    }

    /**
     * Checks a blueprint for problems without touching anything.
     *
     * @param string $file path to a blueprint, or the name of one in the blueprint directory
     */
    public function actionLint(string $file): int
    {
        $blueprint = $this->read($file);

        if ($blueprint === null) {
            return ExitCode::DATAERR;
        }

        Plugin::getInstance()->planner->normalize($blueprint);
        $report = Plugin::getInstance()->linter->lint($blueprint);

        foreach ($report->warningMessages() as $warning) {
            $this->stdout('  warning  ', Console::FG_YELLOW);
            $this->stdout($warning . PHP_EOL);
        }

        foreach ($report->errorMessages() as $error) {
            $this->stdout('  error    ', Console::FG_RED);
            $this->stdout($error . PHP_EOL);
        }

        if ($report->isClean()) {
            $this->stdout(sprintf(
                '%s is valid: %d components across %d types.' . PHP_EOL,
                $blueprint->label(),
                $blueprint->total(),
                count($blueprint->types())
            ), Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(PHP_EOL . count($report->errors) . ' error(s).' . PHP_EOL, Console::FG_RED);

        return ExitCode::DATAERR;
    }

    /**
     * Shows what applying a blueprint would do, and does none of it.
     */
    public function actionPlan(string $file): int
    {
        $blueprint = $this->read($file);

        if ($blueprint === null) {
            return ExitCode::DATAERR;
        }

        $plan = Plugin::getInstance()->planner->plan($blueprint, $this->planOptions());

        $this->printPlan($plan);

        if (!$plan->isApplicable()) {
            return ExitCode::DATAERR;
        }

        // Exit 0 with no work, 0 with work — a plan is not a failure either way. The
        // useful distinction for CI is "would this apply cleanly", which is what the
        // error codes above cover.
        return ExitCode::OK;
    }

    /**
     * Applies a blueprint, after showing the plan and asking.
     */
    public function actionApply(string $file): int
    {
        $blueprint = $this->read($file);

        if ($blueprint === null) {
            return ExitCode::DATAERR;
        }

        $plan = Plugin::getInstance()->planner->plan($blueprint, $this->planOptions());

        $this->printPlan($plan);

        if (!$plan->isApplicable()) {
            return ExitCode::DATAERR;
        }

        if (!$plan->hasWork()) {
            return ExitCode::OK;
        }

        if ($this->interactive && !$this->confirm(PHP_EOL . 'Apply these changes?', true)) {
            $this->stdout('Nothing was changed.' . PHP_EOL);
            return ExitCode::OK;
        }

        $document = $blueprint->source !== null && is_file($blueprint->source)
            ? (string)file_get_contents($blueprint->source)
            : null;

        $result = Plugin::getInstance()->applier->apply($plan, $document);

        foreach ($result->warnings as $warning) {
            $this->stdout('  warning  ', Console::FG_YELLOW);
            $this->stdout($warning . PHP_EOL);
        }

        if (!$result->isSuccess()) {
            $this->stdout(PHP_EOL . 'Failed on ' . $result->failedOn . ': ' . $result->error . PHP_EOL, Console::FG_RED);
            $this->stdout(sprintf(
                '%d component(s) were changed before it stopped. Roll them back with `craft archie/history/rollback %d`.' . PHP_EOL,
                $result->count(),
                $result->runId
            ));

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(PHP_EOL . sprintf('Applied %d component(s). Run %d.', $result->count(), $result->runId) . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Writes the live content model out as a blueprint.
     */
    public function actionExport(): int
    {
        $handlers = Plugin::getInstance()->handlers;
        $types = $this->only !== '' ? $this->split($this->only) : $handlers->types();
        $handles = $this->handles !== '' ? $this->split($this->handles) : ['*'];

        $selection = [];
        foreach ($types as $type) {
            if ($handlers->get($type) === null) {
                $this->stderr(sprintf('Unknown component type “%s”. Known types: %s' . PHP_EOL, $type, implode(', ', $handlers->types())), Console::FG_RED);
                return ExitCode::DATAERR;
            }
            $selection[$type] = $handles;
        }

        $blueprint = Plugin::getInstance()->exporter->export($selection, [
            'dependencies' => !$this->bare,
        ]);

        $format = $this->format !== '' ? $this->format : Plugin::getInstance()->getSettings()->defaultFormat;
        $output = Plugin::getInstance()->blueprints->dump($blueprint, $format);

        if ($this->out === '') {
            $this->stdout($output);
            return ExitCode::OK;
        }

        FileHelper::writeToFile($this->out, $output);
        $this->stdout(sprintf('Wrote %d component(s) to %s' . PHP_EOL, $blueprint->total(), $this->out), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Rewrites an Architect blueprint as an Archie one.
     *
     * Nothing is applied — this reads the old document, resolves the Craft 4 shapes it
     * used, and prints the result for you to read before you trust it.
     *
     * @param string $file path to an Architect JSON or YAML blueprint
     */
    public function actionConvert(string $file): int
    {
        $blueprint = $this->read($file);

        if ($blueprint === null) {
            return ExitCode::DATAERR;
        }

        Plugin::getInstance()->planner->normalize($blueprint);

        foreach ($blueprint->notices as $notice) {
            $this->stderr('  converted  ', Console::FG_CYAN);
            $this->stderr($notice . PHP_EOL);
        }

        $format = $this->format !== '' ? $this->format : 'yaml';
        $output = Plugin::getInstance()->blueprints->dump($blueprint, $format);

        if ($this->out === '') {
            $this->stdout($output);
            return ExitCode::OK;
        }

        FileHelper::writeToFile($this->out, $output);
        $this->stdout(sprintf('Wrote %s' . PHP_EOL, $this->out), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Reads a blueprint by path, by name in the blueprint directory, or by recipe handle,
     * reporting parse failures rather than throwing.
     */
    private function read(string $file): ?Blueprint
    {
        $plugin = Plugin::getInstance();
        $vars = $this->parseVars();

        try {
            if (is_file($file)) {
                return $plugin->blueprints->openFile($file, $vars);
            }

            // A bare word with no extension is a recipe handle before it is anything else.
            if ($plugin->recipes->get($file) !== null) {
                return $plugin->recipes->open($file, $vars);
            }

            return $plugin->blueprints->open($file, $vars);
        } catch (\Throwable $e) {
            $this->stderr('  error  ', Console::FG_RED);
            $this->stderr($e->getMessage() . PHP_EOL);
            return null;
        }
    }

    private function planOptions(): array
    {
        return [
            'force' => $this->force,
            'prune' => $this->prune,
            'types' => $this->types !== '' ? $this->split($this->types) : null,
        ];
    }

    /** @return array<string, string> */
    private function parseVars(): array
    {
        $vars = [];

        foreach ($this->split($this->vars) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $vars[trim($name)] = $value;
        }

        return $vars;
    }

    /** @return string[] */
    private function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
    }

    private function printPlan(Plan $plan): void
    {
        $blueprint = $plan->blueprint;

        $this->stdout(PHP_EOL . $blueprint->label() . PHP_EOL, Console::BOLD);
        if ($blueprint->description !== '') {
            $this->stdout($blueprint->description . PHP_EOL);
        }
        $this->stdout(PHP_EOL);

        foreach ($plan->warnings as $warning) {
            $this->stdout('  warning  ', Console::FG_YELLOW);
            $this->stdout($warning . PHP_EOL);
        }

        foreach ($plan->errors as $error) {
            $this->stdout('  error    ', Console::FG_RED);
            $this->stdout($error . PHP_EOL);
        }

        if ($plan->errors !== []) {
            $this->stdout(PHP_EOL . 'Nothing can be applied until those are fixed.' . PHP_EOL, Console::FG_RED);
            return;
        }

        foreach ($plan->byType() as $type => $items) {
            $this->stdout(PHP_EOL . $items[0]->typeLabel . PHP_EOL, Console::BOLD);

            foreach ($items as $item) {
                [$symbol, $color] = match ($item->action) {
                    PlanItem::ACTION_CREATE => ['  +  ', Console::FG_GREEN],
                    PlanItem::ACTION_UPDATE => ['  ~  ', Console::FG_YELLOW],
                    PlanItem::ACTION_DELETE => ['  -  ', Console::FG_RED],
                    PlanItem::ACTION_CONFLICT => ['  !  ', Console::FG_RED],
                    default => ['     ', Console::FG_GREY],
                };

                $this->stdout($symbol, $color);
                $this->stdout(str_pad($item->handle, 30));
                $this->stdout($item->summary() . PHP_EOL, Console::FG_GREY);

                if ($item->ignored !== []) {
                    $this->stdout(sprintf(
                        "       ignored: %s  (this component has no such setting)" . PHP_EOL,
                        implode(', ', $item->ignored)
                    ), Console::FG_YELLOW);
                }

                foreach ($item->changes as $change) {
                    $this->stdout(sprintf(
                        "       %s: %s → %s%s" . PHP_EOL,
                        $change['path'],
                        Differ::render($change['from']),
                        Differ::render($change['to']),
                        isset($change['note']) ? '  (' . $change['note'] . ')' : ''
                    ), Console::FG_GREY);
                }
            }
        }

        $counts = $plan->counts();

        $this->stdout(PHP_EOL . sprintf(
            'Plan: %d to create, %d to update, %d unchanged%s%s.' . PHP_EOL,
            $counts[PlanItem::ACTION_CREATE],
            $counts[PlanItem::ACTION_UPDATE],
            $counts[PlanItem::ACTION_SKIP],
            $counts[PlanItem::ACTION_DELETE] > 0 ? sprintf(', %d to delete', $counts[PlanItem::ACTION_DELETE]) : '',
            $counts[PlanItem::ACTION_CONFLICT] > 0 ? sprintf(', %d blocked', $counts[PlanItem::ACTION_CONFLICT]) : ''
        ), Console::BOLD);

        if ($plan->conflicts() !== []) {
            $this->stdout('Re-run with --force to allow the blocked changes.' . PHP_EOL, Console::FG_YELLOW);
        }
    }
}
