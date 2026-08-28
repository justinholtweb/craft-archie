<?php

namespace justinholtweb\archie\controllers;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\models\Blueprint;
use yii\web\Response;

/**
 * The plan screen: what a blueprint would do, and the button that does it.
 *
 * Applying is only ever reachable from a plan, and the plan is always rebuilt from the
 * blueprint at the moment of applying rather than trusted from the form. A plan is a
 * description of the site as it was a moment ago, and a moment is long enough.
 */
class PlanController extends BaseController
{
    protected function writeActions(): array
    {
        return ['apply'];
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();

        $source = (string)$request->getParam('source', '');
        $kind = (string)$request->getParam('kind', 'file');
        $contents = (string)$request->getParam('contents', '');
        $force = (bool)$request->getParam('force', false);
        $prune = (bool)$request->getParam('prune', false);
        $vars = $this->parseVars((string)$request->getParam('vars', ''));

        $blueprint = null;
        $error = null;

        try {
            $blueprint = $this->read($kind, $source, $contents, $vars);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $plan = $blueprint !== null
            ? Plugin::getInstance()->planner->plan($blueprint, ['force' => $force, 'prune' => $prune])
            : null;

        return $this->renderTemplate('archie/plan/index', [
            'plan' => $plan,
            'blueprint' => $blueprint,
            'error' => $error,
            'kind' => $kind,
            'source' => $source,
            'contents' => $contents,
            'vars' => (string)$request->getParam('vars', ''),
            'force' => $force,
            'prune' => $prune,
            'canApply' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionApply(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $source = (string)$request->getBodyParam('source', '');
        $kind = (string)$request->getBodyParam('kind', 'file');
        $contents = (string)$request->getBodyParam('contents', '');
        $force = (bool)$request->getBodyParam('force', false);
        $prune = (bool)$request->getBodyParam('prune', false);
        $varsRaw = (string)$request->getBodyParam('vars', '');

        try {
            $blueprint = $this->read($kind, $source, $contents, $this->parseVars($varsRaw));
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
        }

        $plan = Plugin::getInstance()->planner->plan($blueprint, ['force' => $force, 'prune' => $prune]);
        $document = $kind === 'paste' ? $contents : null;

        if ($document === null && $blueprint->source !== null && is_file($blueprint->source)) {
            $document = (string)file_get_contents($blueprint->source);
        }

        $result = Plugin::getInstance()->applier->apply($plan, $document);

        foreach ($result->warnings as $warning) {
            Craft::$app->getSession()->setNotice($warning);
        }

        if (!$result->isSuccess()) {
            $this->setFailFlash(Craft::t('archie', 'Stopped at {where}: {error}', [
                'where' => $result->failedOn ?? 'the start',
                'error' => $result->error,
            ]));

            return $result->runId !== null
                ? $this->redirect(UrlHelper::cpUrl('archie/history/' . $result->runId))
                : $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
        }

        $this->setSuccessFlash(Craft::t('archie', 'Applied {count} component(s).', ['count' => $result->count()]));

        return $result->runId !== null
            ? $this->redirect(UrlHelper::cpUrl('archie/history/' . $result->runId))
            : $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
    }

    private function read(string $kind, string $source, string $contents, array $vars): Blueprint
    {
        $plugin = Plugin::getInstance();

        return match ($kind) {
            'paste' => $plugin->blueprints->parse($contents, $vars),
            'recipe' => $plugin->recipes->open($source, $vars),
            default => $plugin->blueprints->open($source, $vars),
        };
    }

    /**
     * Reads the variables textarea, one `name = value` per line.
     *
     * @return array<string, string>
     */
    private function parseVars(string $raw): array
    {
        $vars = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $vars[trim($name)] = trim($value);
        }

        return $vars;
    }
}
