<?php

namespace justinholtweb\archie\controllers;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\archie\Plugin;
use yii\web\Response;

/**
 * Reading the live content model back out as a blueprint.
 */
class ExportController extends BaseController
{
    protected function writeActions(): array
    {
        // Saving into the project writes a file, but not to project config, so it does
        // not need `allowAdminChanges` — capturing a production model is exactly what you
        // want to be able to do on production.
        return [];
    }

    public function actionIndex(): Response
    {
        $handlers = Plugin::getInstance()->handlers;
        $inventory = [];

        foreach ($handlers->all() as $type => $handler) {
            $components = [];

            foreach ($handler->all() as $component) {
                $handle = $handler->handleOf($component);
                if ($handle === null) {
                    continue;
                }
                $components[] = ['handle' => $handle, 'name' => $handler->nameOf($component)];
            }

            usort($components, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));

            $inventory[$type] = [
                'label' => $handler::label(),
                'icon' => $handler::icon(),
                'components' => $components,
            ];
        }

        return $this->renderTemplate('archie/export/index', [
            'inventory' => $inventory,
            'defaultFormat' => Plugin::getInstance()->getSettings()->defaultFormat,
        ]);
    }

    public function actionExport(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $selection = [];

        foreach ((array)$request->getBodyParam('selection', []) as $type => $handles) {
            $handles = array_values(array_filter((array)$handles, fn($handle) => $handle !== ''));
            if ($handles !== []) {
                $selection[$type] = $handles;
            }
        }

        if ($selection === []) {
            $this->setFailFlash(Craft::t('archie', 'Nothing was selected.'));
            return $this->redirect(UrlHelper::cpUrl('archie/export'));
        }

        $plugin = Plugin::getInstance();
        $format = $request->getBodyParam('format') === 'json' ? 'json' : 'yaml';

        $blueprint = $plugin->exporter->export($selection, [
            'dependencies' => (bool)$request->getBodyParam('dependencies', true),
            'name' => (string)$request->getBodyParam('blueprintName', ''),
            'description' => (string)$request->getBodyParam('description', ''),
        ]);

        $output = $plugin->blueprints->dump($blueprint, $format);
        $filename = $this->filename((string)$request->getBodyParam('filename', ''), $format);

        if ($request->getBodyParam('destination') === 'download') {
            return Craft::$app->getResponse()->sendContentAsFile(
                $output,
                $filename,
                ['mimeType' => $format === 'json' ? 'application/json' : 'application/x-yaml']
            );
        }

        try {
            $plugin->blueprints->write($filename, $output);
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirect(UrlHelper::cpUrl('archie/export'));
        }

        $this->setSuccessFlash(Craft::t('archie', 'Exported {count} component(s).', ['count' => $blueprint->total()]));

        return $this->redirect(UrlHelper::cpUrl('archie/blueprints/edit/' . $filename));
    }

    private function filename(string $requested, string $format): string
    {
        $name = trim($requested) !== '' ? trim($requested) : 'blueprint';
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '-', $name) ?? 'blueprint';
        $name = preg_replace('/\.(ya?ml|json)$/i', '', $name) ?? $name;

        return $name . ($format === 'json' ? '.json' : '.yaml');
    }
}
