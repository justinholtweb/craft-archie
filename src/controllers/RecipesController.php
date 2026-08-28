<?php

namespace justinholtweb\archie\controllers;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\archie\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The blueprints that ship with Archie.
 */
class RecipesController extends BaseController
{
    protected function writeActions(): array
    {
        return ['copy'];
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('archie/recipes/index', [
            'recipes' => Plugin::getInstance()->recipes->all(),
        ]);
    }

    public function actionDetail(string $handle): Response
    {
        $recipes = Plugin::getInstance()->recipes;
        $recipe = $recipes->get($handle);

        if ($recipe === null) {
            throw new NotFoundHttpException('No such recipe.');
        }

        return $this->renderTemplate('archie/recipes/detail', [
            'recipe' => $recipe,
            'contents' => $recipes->read($handle),
        ]);
    }

    /**
     * Copies a recipe into the project's blueprint directory.
     *
     * A recipe is a starting point, and the only way to treat it as one is to make it
     * yours: applying it straight from the plugin would leave the project with a content
     * model whose source of truth lives inside a dependency.
     */
    public function actionCopy(): Response
    {
        $this->requirePostRequest();

        $handle = (string)Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $plugin = Plugin::getInstance();

        try {
            $name = "$handle.yaml";
            $plugin->blueprints->write($name, $plugin->recipes->read($handle));
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirect(UrlHelper::cpUrl('archie/recipes'));
        }

        $this->setSuccessFlash(Craft::t('archie', 'Copied to {name}. Edit it before applying.', ['name' => $name]));

        return $this->redirect(UrlHelper::cpUrl('archie/blueprints/edit/' . $name));
    }
}
