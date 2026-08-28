<?php

namespace justinholtweb\archie\controllers;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\archie\Plugin;
use yii\web\Response;

/**
 * Archie's own settings.
 */
class SettingsController extends BaseController
{
    protected function writeActions(): array
    {
        return ['save'];
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('archie/settings/index', [
            'settings' => $plugin->getSettings(),
            'directory' => $plugin->blueprints->directory(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash(Craft::t('archie', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('archie', 'Settings saved.'));

        return $this->redirect(UrlHelper::cpUrl('archie/settings'));
    }
}
