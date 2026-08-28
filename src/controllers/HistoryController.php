<?php

namespace justinholtweb\archie\controllers;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use justinholtweb\archie\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Previous applies, and undoing one.
 */
class HistoryController extends BaseController
{
    protected function writeActions(): array
    {
        return ['rollback'];
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('archie/history/index', [
            'runs' => Plugin::getInstance()->snapshots->all(),
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $snapshots = Plugin::getInstance()->snapshots;
        $run = $snapshots->get($id);

        if ($run === null) {
            throw new NotFoundHttpException('No such run.');
        }

        $summary = Json::decodeIfJson((string)$run->summary);

        return $this->renderTemplate('archie/history/detail', [
            'run' => $run,
            'items' => $snapshots->items($id),
            'rollbackSteps' => $run->rolledBack ? [] : $snapshots->describeRollback($id),
            'summary' => is_array($summary) ? $summary : [],
            'handlers' => Plugin::getInstance()->handlers->labels(),
        ]);
    }

    public function actionRollback(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $result = Plugin::getInstance()->snapshots->rollback($id);

        foreach ($result->warnings as $warning) {
            Craft::$app->getSession()->setNotice($warning);
        }

        if (!$result->isSuccess()) {
            $this->setFailFlash(Craft::t('archie', 'Stopped at {where}: {error}', [
                'where' => $result->failedOn ?? 'the start',
                'error' => $result->error,
            ]));
        } else {
            $this->setSuccessFlash(Craft::t('archie', 'Reverted {count} component(s).', ['count' => $result->count()]));
        }

        return $this->redirect(UrlHelper::cpUrl('archie/history/' . $id));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->snapshots->delete($id);

        $this->setSuccessFlash(Craft::t('archie', 'Record deleted.'));

        return $this->redirect(UrlHelper::cpUrl('archie/history'));
    }
}
