<?php

namespace justinholtweb\archie\records;

use craft\db\ActiveRecord;
use justinholtweb\archie\migrations\Install;

/**
 * One component touched by one apply.
 *
 * @property int $id
 * @property int $runId
 * @property string $type
 * @property string $handle
 * @property string $action
 * @property string|null $componentUid
 * @property string|null $before
 * @property int $sortOrder
 */
class RunItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::RUN_ITEMS;
    }
}
