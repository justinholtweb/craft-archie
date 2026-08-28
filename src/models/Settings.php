<?php

namespace justinholtweb\archie\models;

use craft\base\Model;
use Psr\Log\LogLevel;

/**
 * Archie's settings.
 *
 * Nothing here is `required` — a fresh install has to be able to save settings before
 * anything is configured.
 */
class Settings extends Model
{
    /**
     * Directory that on-disk blueprints are read from and written to, relative to the
     * Craft base path. Blueprints belong in version control next to project config.
     */
    public string $blueprintPath = 'config/archie';

    /** Default serialisation format for exports: `yaml` or `json`. */
    public string $defaultFormat = 'yaml';

    /**
     * Whether an apply is ever allowed to delete live components that the blueprint
     * does not mention. Off by default: a blueprint is a description of what should
     * exist, not an exhaustive inventory of what may.
     */
    public bool $allowPrune = false;

    /** How many apply snapshots to keep. Older ones are pruned after each apply. 0 = keep all. */
    public int $keepSnapshots = 25;

    /**
     * Whether entry types, fields and volumes created by Archie get a generated handle
     * when the blueprint omits one. Off means a missing handle is a lint error.
     */
    public bool $generateMissingHandles = true;

    /** Log level for storage/logs/archie.log. */
    public string $logLevel = LogLevel::INFO;

    public function rules(): array
    {
        return [
            [['blueprintPath', 'defaultFormat', 'logLevel'], 'string'],
            [['allowPrune', 'generateMissingHandles'], 'boolean'],
            [['keepSnapshots'], 'integer', 'min' => 0],
            [['defaultFormat'], 'in', 'range' => ['yaml', 'json']],
            [['blueprintPath'], 'match', 'pattern' => '/^[A-Za-z0-9._\-\/]*$/',
                'message' => 'The blueprint path may only contain letters, numbers, dots, dashes, underscores and slashes.'],
            [['blueprintPath'], 'match', 'pattern' => '/\.\./', 'not' => true,
                'message' => 'The blueprint path may not walk up out of the project.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'blueprintPath' => 'Blueprint directory',
            'defaultFormat' => 'Default format',
            'allowPrune' => 'Allow pruning',
            'keepSnapshots' => 'Snapshots to keep',
            'generateMissingHandles' => 'Generate missing handles',
            'logLevel' => 'Log level',
        ];
    }
}
