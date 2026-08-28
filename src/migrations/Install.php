<?php

namespace justinholtweb\archie\migrations;

use craft\db\Migration;

/**
 * Creates the tables that make an apply reversible.
 */
class Install extends Migration
{
    public const RUNS = '{{%archie_runs}}';
    public const RUN_ITEMS = '{{%archie_runitems}}';

    public function safeUp(): bool
    {
        $this->createTable(self::RUNS, [
            'id' => $this->primaryKey(),
            'blueprintName' => $this->string()->notNull()->defaultValue(''),
            'source' => $this->string(500),
            'format' => $this->string(10)->notNull()->defaultValue('yaml'),
            // The document itself, so a run can be re-read and re-applied even after the
            // file it came from has changed or gone.
            'document' => $this->mediumText(),
            'summary' => $this->text(),
            'userId' => $this->integer(),
            'itemCount' => $this->integer()->notNull()->defaultValue(0),
            'rolledBack' => $this->boolean()->notNull()->defaultValue(false),
            'dateRolledBack' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(self::RUN_ITEMS, [
            'id' => $this->primaryKey(),
            'runId' => $this->integer()->notNull(),
            'type' => $this->string(60)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'action' => $this->string(20)->notNull(),
            'componentUid' => $this->string(36),
            // What the component looked like before this run touched it. Null for a
            // create, which is undone by deleting rather than restoring.
            'before' => $this->mediumText(),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::RUNS, ['dateCreated']);
        $this->createIndex(null, self::RUN_ITEMS, ['runId', 'sortOrder']);

        $this->addForeignKey(null, self::RUN_ITEMS, ['runId'], self::RUNS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, self::RUNS, ['userId'], '{{%users}}', ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::RUN_ITEMS);
        $this->dropTableIfExists(self::RUNS);

        return true;
    }
}
