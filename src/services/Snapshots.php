<?php

namespace justinholtweb\archie\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\Json;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\ApplyResult;
use justinholtweb\archie\models\PlanItem;
use justinholtweb\archie\records\RunItemRecord;
use justinholtweb\archie\records\RunRecord;

/**
 * The history of applies, and the ability to undo one.
 *
 * Every apply writes down what each component looked like before it was touched. That
 * record is what turns "scaffolding" from a one-way door into something you can try.
 */
class Snapshots extends Component
{
    /** @return RunRecord[] newest first */
    public function all(int $limit = 50): array
    {
        return RunRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function get(int $id): ?RunRecord
    {
        return RunRecord::findOne($id);
    }

    /** @return RunItemRecord[] in the order they were applied */
    public function items(int $runId): array
    {
        return RunItemRecord::find()
            ->where(['runId' => $runId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();
    }

    /**
     * What rolling this run back would do, so it can be shown before it is done.
     *
     * @return array<int, array{type: string, handle: string, action: string, label: string, destructive: bool}>
     */
    public function describeRollback(int $runId): array
    {
        $handlers = Plugin::getInstance()->handlers;
        $described = [];

        foreach (array_reverse($this->items($runId)) as $item) {
            $handler = $handlers->get($item->type);
            $label = $handler !== null ? $handler::singular() : $item->type;

            $described[] = match ($item->action) {
                PlanItem::ACTION_CREATE => [
                    'type' => $item->type,
                    'handle' => $item->handle,
                    'action' => 'delete',
                    'label' => sprintf('Delete the %s “%s”', $label, $item->handle),
                    // Deleting a section or a volume takes its content with it.
                    'destructive' => $this->isContentBearing($item->type),
                ],
                PlanItem::ACTION_UPDATE => [
                    'type' => $item->type,
                    'handle' => $item->handle,
                    'action' => 'restore',
                    'label' => sprintf('Restore the %s “%s” to how it was', $label, $item->handle),
                    'destructive' => false,
                ],
                PlanItem::ACTION_DELETE => [
                    'type' => $item->type,
                    'handle' => $item->handle,
                    'action' => 'recreate',
                    'label' => sprintf('Recreate the %s “%s” (without its content)', $label, $item->handle),
                    'destructive' => false,
                ],
                default => null,
            } ?? [];
        }

        return array_values(array_filter($described));
    }

    /**
     * Undoes a run, newest change first.
     *
     * Recreating something Archie deleted brings back the structure, never the content —
     * a rollback is an apology, not a time machine, and saying so plainly is better than
     * letting someone believe their entries are coming back.
     */
    public function rollback(int $runId): ApplyResult
    {
        $result = new ApplyResult();
        $run = $this->get($runId);

        if ($run === null) {
            $result->error = 'That run no longer exists.';
            return $result;
        }

        if ($run->rolledBack) {
            $result->error = 'That run has already been rolled back.';
            return $result;
        }

        $handlers = Plugin::getInstance()->handlers;
        $context = new ApplyContext();

        foreach (array_reverse($this->items($runId)) as $item) {
            $handler = $handlers->get($item->type);

            if ($handler === null) {
                $context->warn(sprintf('Skipped “%s”: nothing handles %s any more.', $item->handle, $item->type));
                continue;
            }

            $before = $item->before !== null ? Json::decodeIfJson($item->before) : null;

            try {
                if ($item->action === PlanItem::ACTION_CREATE) {
                    $component = $handler->find($item->handle);
                    if ($component !== null) {
                        $handler->delete($component);
                    }
                } elseif (is_array($before)) {
                    $handler->apply($handler->normalize($before), $handler->find($item->handle), $context);
                } else {
                    $context->warn(sprintf('Skipped “%s”: no record of what it looked like before.', $item->handle));
                    continue;
                }
            } catch (\Throwable $e) {
                $result->error = $e->getMessage();
                $result->failedOn = $handler::label() . ' “' . $item->handle . '”';
                Craft::error(
                    sprintf('Rollback of run %d failed on %s: %s', $runId, $result->failedOn, $e->getMessage()),
                    Plugin::LOG_CATEGORY
                );
                break;
            }

            $result->applied[] = ['type' => $item->type, 'handle' => $item->handle, 'action' => 'reverted'];
        }

        $result->warnings = $context->warnings;

        if ($result->isSuccess()) {
            $run->rolledBack = true;
            $run->dateRolledBack = Db::prepareDateForDb(new \DateTime());
            $run->save(false);
        }

        $result->runId = $runId;

        return $result;
    }

    /** Deletes the oldest runs once there are more than the settings allow. */
    public function prune(): void
    {
        $keep = Plugin::getInstance()->getSettings()->keepSnapshots;

        if ($keep <= 0) {
            return;
        }

        $ids = RunRecord::find()
            ->select(['id'])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->offset($keep)
            ->limit(1000)
            ->column();

        if ($ids !== []) {
            RunRecord::deleteAll(['id' => $ids]);
        }
    }

    public function delete(int $runId): bool
    {
        return (bool)RunRecord::deleteAll(['id' => $runId]);
    }

    /** Whether deleting this kind of component takes content with it. */
    private function isContentBearing(string $type): bool
    {
        return in_array($type, ['sections', 'entryTypes', 'volumes', 'categoryGroups', 'tagGroups', 'globalSets', 'fields'], true);
    }
}
