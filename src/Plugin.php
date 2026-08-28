<?php

namespace justinholtweb\archie;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\web\UrlManager;
use justinholtweb\archie\models\Settings;
use justinholtweb\archie\services\Applier;
use justinholtweb\archie\services\Blueprints;
use justinholtweb\archie\services\Exporter;
use justinholtweb\archie\services\Handlers;
use justinholtweb\archie\services\Linter;
use justinholtweb\archie\services\Planner;
use justinholtweb\archie\services\Recipes;
use justinholtweb\archie\services\Snapshots;
use yii\base\Event;

/**
 * Archie — author a Craft content model as a blueprint, then plan it, apply it and undo it.
 *
 * @property-read Blueprints $blueprints
 * @property-read Handlers $handlers
 * @property-read Linter $linter
 * @property-read Planner $planner
 * @property-read Applier $applier
 * @property-read Exporter $exporter
 * @property-read Snapshots $snapshots
 * @property-read Recipes $recipes
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'archie';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'blueprints' => Blueprints::class,
                'handlers' => Handlers::class,
                'linter' => Linter::class,
                'planner' => Planner::class,
                'applier' => Applier::class,
                'exporter' => Exporter::class,
                'snapshots' => Snapshots::class,
                'recipes' => Recipes::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
    }

    /**
     * Sends Archie's log entries to a dedicated storage/logs/archie.log target — an
     * apply that half-worked is worth being able to read back later.
     */
    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    /**
     * Archie edits the content model, which in Craft is admin territory — the same
     * territory as Settings → Fields. There is no permission short of admin that makes
     * sense to grant for it, so the whole section is hidden from everyone else.
     */
    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }

        $item = parent::getCpNavItem();

        $item['subnav'] = [
            'blueprints' => ['label' => Craft::t('archie', 'Blueprints'), 'url' => 'archie/blueprints'],
            'recipes' => ['label' => Craft::t('archie', 'Recipes'), 'url' => 'archie/recipes'],
            'export' => ['label' => Craft::t('archie', 'Export'), 'url' => 'archie/export'],
            'history' => ['label' => Craft::t('archie', 'History'), 'url' => 'archie/history'],
            'settings' => ['label' => Craft::t('archie', 'Settings'), 'url' => 'archie/settings'],
        ];

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('archie/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('archie/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['archie'] = 'archie/blueprints/index';
                $event->rules['archie/blueprints'] = 'archie/blueprints/index';
                $event->rules['archie/blueprints/new'] = 'archie/blueprints/edit';
                $event->rules['archie/blueprints/edit/<name:[^\/]+>'] = 'archie/blueprints/edit';
                $event->rules['archie/blueprints/plan'] = 'archie/plan/index';
                $event->rules['archie/recipes'] = 'archie/recipes/index';
                $event->rules['archie/recipes/<handle:[^\/]+>'] = 'archie/recipes/detail';
                $event->rules['archie/export'] = 'archie/export/index';
                $event->rules['archie/history'] = 'archie/history/index';
                $event->rules['archie/history/<id:\d+>'] = 'archie/history/detail';
                $event->rules['archie/settings'] = 'archie/settings/index';
            }
        );
    }
}
