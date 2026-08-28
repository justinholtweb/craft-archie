<?php

namespace justinholtweb\archie\services;

use Craft;
use craft\base\Component;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Checks a blueprint before anything is planned or applied.
 *
 * Everything here is a question that can be answered without writing: does this type
 * exist, does this handle point at anything, would Craft even accept a write right now.
 */
class Linter extends Component
{
    /**
     * @param Blueprint $blueprint a blueprint whose specs have already been normalised
     */
    public function lint(Blueprint $blueprint): LintReport
    {
        $report = new LintReport();

        $this->lintDocument($blueprint, $report);
        $this->lintEnvironment($report);

        $handlers = Plugin::getInstance()->handlers;

        foreach ($blueprint->components as $type => $specs) {
            $handler = $handlers->get($type);

            if ($handler === null) {
                $report->error(sprintf('No handler for component type “%s”.', $type));
                continue;
            }

            $this->lintDuplicates($type, $specs, $handler::label(), $report);

            foreach ($specs as $spec) {
                $handler->lint($spec, $report, $blueprint);
            }
        }

        return $report;
    }

    private function lintDocument(Blueprint $blueprint, LintReport $report): void
    {
        if ($blueprint->version > Blueprint::SCHEMA_VERSION) {
            $report->error(sprintf(
                'This blueprint declares schema version %d, but this version of Archie only understands version %d.',
                $blueprint->version,
                Blueprint::SCHEMA_VERSION
            ));
        }

        if ($blueprint->components === []) {
            $report->error('The blueprint declares no components.');
        }

        foreach ($blueprint->missingVars as $var) {
            $report->error(sprintf(
                'The blueprint uses `{{ %s }}` but nothing supplies a value for it. Add it under `vars`, or pass `--var %s=…`.',
                $var,
                $var
            ));
        }

        foreach ($blueprint->notices as $notice) {
            $report->warn($notice);
        }
    }

    /**
     * The blueprint can be perfect and the apply still impossible: `allowAdminChanges`
     * is off in production for exactly this reason.
     */
    private function lintEnvironment(LintReport $report): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $report->error(
                'This environment has `allowAdminChanges` disabled, so Craft will not accept content model changes. '
                . 'Apply the blueprint where admin changes are allowed and deploy the resulting project config.'
            );
        }

        if (Craft::$app->getProjectConfig()->readOnly) {
            $report->error('Project config is in read-only mode, so nothing can be written.');
        }
    }

    /** @param array $specs */
    private function lintDuplicates(string $type, array $specs, string $label, LintReport $report): void
    {
        $seen = [];

        foreach ($specs as $spec) {
            $handle = $spec['handle'] ?? '';
            if ($handle === '') {
                continue;
            }
            if (isset($seen[$handle])) {
                $report->error(
                    sprintf('“%s” is declared more than once. A blueprint may only define each handle once.', $handle),
                    strtolower($label)
                );
            }
            $seen[$handle] = true;
        }
    }
}
