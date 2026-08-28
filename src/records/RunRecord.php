<?php

namespace justinholtweb\archie\records;

use craft\db\ActiveRecord;
use justinholtweb\archie\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * One apply. Kept whether it succeeded or not, because a half-finished apply is exactly
 * the one you want a record of.
 *
 * @property int $id
 * @property string $blueprintName
 * @property string|null $source
 * @property string $format
 * @property string|null $document
 * @property string|null $summary
 * @property int|null $userId
 * @property int $itemCount
 * @property bool $rolledBack
 */
class RunRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::RUNS;
    }

    public function getItems(): ActiveQueryInterface
    {
        return $this->hasMany(RunItemRecord::class, ['runId' => 'id']);
    }
}
