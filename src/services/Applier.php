<?php

namespace justinholtweb\archie\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\ApplyResult;
use justinholtweb\archie\models\Plan;
use justinholtweb\archie\models\PlanItem;
use justinholtweb\archie\records\RunItemRecord;
use justinholtweb\archie\records\RunRecord;

/**
 * Executes a plan.
 *
 * The applier does as little thinking as it can: everything worth deciding was decided
 * while the plan was built. What it does own is the order, the record of what it did,
 * and stopping the moment something goes wrong rather than pressing on and leaving a
 * content model half-built with no account of which half.
 */
class Applier extends Component
{
    /**
     * @param string|null $document the blueprint's source text, stored so the run can be
     *                              rolled back or re-read after the file has moved on
     */
    public function apply(Plan $plan, ?string $document = null): ApplyResult
    {
        $result = new ApplyResult();

        if (!$plan->isApplicable()) {
            $result->error = $plan->blocked
                ?? ($plan->errors[0] ?? 'The plan has unresolved conflicts and cannot be applied.');
            return $result;
        }

        $items = $plan->actionable();

        if ($items === []) {
            return $result;
        }

        // Apply order is the handler stage order the plan was built in; deletes were
        // given inverted stages so dependants go before the things they depend on.
        usort($items, fn(PlanItem $a, PlanItem $b) => $a->stage <=> $b->stage);

        $run = $this->startRun($plan, $document);
        $result->runId = $run->id;

        $context = new ApplyContext();
        $handlers = Plugin::getInstance()->handlers;
        $sortOrder = 0;

        foreach ($items as $item) {
            $handler = $handlers->get($item->type);

            if ($handler === null) {
                continue;
            }

            try {
                if ($item->action === PlanItem::ACTION_DELETE) {
                    $component = $handler->find($item->handle);
                    if ($component !== null) {
                        $handler->delete($component);
                    }
                } else {
                    $existing = $handler->find($item->handle);
                    $spec = $item->spec;

                    // On an update, hand the handler only what the blueprint stated, so a
                    // derived name cannot overwrite the real one.
                    if ($existing !== null) {
                        $spec = \justinholtweb\archie\components\BaseComponentHandler::stated($spec) + ['handle' => $item->handle];
                    }

                    $handler->apply($spec, $existing, $context);
                }
            } catch (\Throwable $e) {
                $result->error = $e->getMessage();
                $result->failedOn = $item->typeLabel . ' “' . $item->handle . '”';
                Craft::error(
                    sprintf('Apply failed on %s: %s', $result->failedOn, $e->getMessage()),
                    Plugin::LOG_CATEGORY
                );
                break;
            }

            $this->recordItem($run, $item, $sortOrder++);
            $result->applied[] = ['type' => $item->type, 'handle' => $item->handle, 'action' => $item->action];
        }

        if ($result->isSuccess()) {
            $this->relink($context, $result);
        }

        $result->warnings = $context->warnings;

        $run->itemCount = count($result->applied);
        $run->summary = Json::encode([
            'counts' => $plan->counts(),
            'warnings' => $result->warnings,
            'error' => $result->error,
        ]);
        $run->save(false);

        Plugin::getInstance()->snapshots->prune();

        return $result;
    }

    /**
     * The second save for anything that had to be written before the things it points at
     * existed — a Matrix field created in the same run as its entry types.
     */
    private function relink(ApplyContext $context, ApplyResult $result): void
    {
        $handlers = Plugin::getInstance()->handlers;

        foreach ($context->deferrals as $deferral) {
            $handler = $handlers->get($deferral['type']);

            if ($handler === null) {
                continue;
            }

            try {
                $handler->relink($deferral['spec'], $context);
            } catch (\Throwable $e) {
                $result->error = $e->getMessage();
                $result->failedOn = sprintf('%s “%s” (linking references)', $handler::label(), $deferral['handle']);
                break;
            }
        }
    }

    private function startRun(Plan $plan, ?string $document): RunRecord
    {
        $blueprint = $plan->blueprint;

        $run = new RunRecord([
            'blueprintName' => $blueprint?->label() ?? 'Blueprint',
            'source' => $blueprint?->source,
            'format' => $blueprint?->format ?? 'yaml',
            'document' => $document,
            'userId' => Craft::$app->getUser()->getIdentity()?->id,
            'itemCount' => 0,
        ]);

        $run->save(false);

        return $run;
    }

    private function recordItem(RunRecord $run, PlanItem $item, int $sortOrder): void
    {
        (new RunItemRecord([
            'runId' => $run->id,
            'type' => $item->type,
            'handle' => $item->handle,
            'action' => $item->action,
            'componentUid' => $item->existingUid,
            'before' => $item->before !== null ? Json::encode($item->before) : null,
            'sortOrder' => $sortOrder,
        ]))->save(false);
    }
}
