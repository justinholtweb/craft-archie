<?php

namespace justinholtweb\archie\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\UploadedFile;
use justinholtweb\archie\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The blueprint files in the project.
 */
class BlueprintsController extends BaseController
{
    protected function writeActions(): array
    {
        return ['save', 'delete'];
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('archie/blueprints/index', [
            'blueprints' => $plugin->blueprints->all(),
            'directory' => $plugin->blueprints->directory(),
            'recipes' => $plugin->recipes->all(),
        ]);
    }

    public function actionEdit(?string $name = null): Response
    {
        $plugin = Plugin::getInstance();
        $contents = '';

        if ($name !== null) {
            try {
                $contents = $plugin->blueprints->read($name);
            } catch (\Throwable $e) {
                throw new NotFoundHttpException($e->getMessage());
            }
        }

        return $this->renderTemplate('archie/blueprints/edit', [
            'name' => $name,
            'contents' => $contents,
            'directory' => $plugin->blueprints->directory(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $name = trim((string)$request->getBodyParam('name', ''));
        $contents = (string)$request->getBodyParam('contents', '');
        $original = $request->getBodyParam('originalName');

        if ($name === '') {
            $this->setFailFlash(Craft::t('archie', 'A blueprint needs a filename.'));
            return null;
        }

        try {
            Plugin::getInstance()->blueprints->write($name, $contents);

            // Renaming through the editor: the old file goes only once the new one is
            // safely written.
            if ($original !== null && $original !== '' && $original !== $name) {
                Plugin::getInstance()->blueprints->delete((string)$original);
            }
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
            return null;
        }

        $this->setSuccessFlash(Craft::t('archie', 'Blueprint saved.'));

        return $this->redirect(UrlHelper::cpUrl('archie/blueprints/edit/' . $name));
    }

    public function actionUpload(): ?Response
    {
        $this->requirePostRequest();

        $file = UploadedFile::getInstanceByName('blueprint');

        if ($file === null) {
            $this->setFailFlash(Craft::t('archie', 'No file was uploaded.'));
            return $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
        }

        try {
            Plugin::getInstance()->blueprints->write($file->name, (string)file_get_contents($file->tempName));
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
        }

        $this->setSuccessFlash(Craft::t('archie', 'Blueprint uploaded.'));

        return $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $name = (string)Craft::$app->getRequest()->getRequiredBodyParam('name');

        try {
            Plugin::getInstance()->blueprints->delete($name);
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
        }

        $this->setSuccessFlash(Craft::t('archie', 'Blueprint deleted.'));

        return $this->redirect(UrlHelper::cpUrl('archie/blueprints'));
    }
}
