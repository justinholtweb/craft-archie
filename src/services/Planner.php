<?php

namespace justinholtweb\archie\services;

use craft\base\Component;
use justinholtweb\archie\Plugin;
use justinholtweb\archie\components\BaseComponentHandler;
use justinholtweb\archie\components\ComponentHandlerInterface;
use justinholtweb\archie\helpers\Differ;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\Plan;
use justinholtweb\archie\models\PlanItem;

/**
 * Works out what applying a blueprint would do, without doing any of it.
 *
 * This is the part Architect never had. A blueprint you have not run is a guess; a plan
 * is the same blueprint with the answer attached — which of these already exist, which
 * of them differ, and in exactly which attributes.
 */
class Planner extends Component
{
    /**
     * @param array{prune?: bool, force?: bool, types?: string[]} $options
     */
    public function plan(Blueprint $blueprint, array $options = []): Plan
    {
        $this->normalize($blueprint);

        $report = Plugin::getInstance()->linter->lint($blueprint);

        $plan = new Plan([
            'blueprint' => $blueprint,
            'errors' => $report->errorMessages(),
            'warnings' => $report->warningMessages(),
        ]);

        $handlers = Plugin::getInstance()->handlers;
        $only = $options['types'] ?? null;
        $force = (bool)($options['force'] ?? false);

        foreach ($handlers->all() as $type => $handler) {
            if ($only !== null && !in_array($type, $only, true)) {
                continue;
            }

            foreach ($blueprint->specs($type) as $spec) {
                $plan->items[] = $this->planOne($type, $handler, $spec, $force);
            }
        }

        if (!empty($options['prune'])) {
            $plan->items = array_merge($plan->items, $this->planPrune($blueprint, $only));
        }

        return $plan;
    }

    /**
     * Runs every spec through its handler's normaliser once, in place, so that the plan
     * and the apply that follows it can never be looking at different input.
     */
    public function normalize(Blueprint $blueprint): void
    {
        $handlers = Plugin::getInstance()->handlers;

        foreach ($blueprint->components as $type => $specs) {
            $handler = $handlers->get($type);
            if ($handler === null) {
                continue;
            }
            foreach ($specs as $i => $spec) {
                $blueprint->components[$type][$i] = $handler->normalize($spec);
            }
        }
    }

    private function planOne(string $type, ComponentHandlerInterface $handler, array $spec, bool $force): PlanItem
    {
        $item = new PlanItem([
            'type' => $type,
            'typeLabel' => $handler::label(),
            'stage' => $handler::stage(),
            'handle' => (string)($spec['handle'] ?? ''),
            'name' => (string)($spec['name'] ?? ''),
            'spec' => $spec,
        ]);

        $existing = $item->handle !== '' ? $handler->find($item->handle) : null;

        if ($existing === null) {
            $item->action = PlanItem::ACTION_CREATE;
            return $item;
        }

        $item->existingUid = $handler->uidOf($existing);
        $item->before = $handler->dump($existing);

        $conflict = $handler->conflict($spec, $existing);

        if ($conflict !== null && !$force) {
            $item->action = PlanItem::ACTION_CONFLICT;
            $item->reason = $conflict;
            return $item;
        }

        // Only what the blueprint actually stated is compared. Values Archie derived for
        // it — a name made out of a handle — are not claims about how things should be.
        $stated = BaseComponentHandler::stated($spec);
        $item->changes = Differ::compare($stated, $item->before);
        $item->ignored = $this->ignoredKeys($stated, $item->before);

        if ($conflict !== null) {
            $item->reason = $conflict;
            $item->changes[] = [
                'path' => 'type',
                'from' => $item->before['type'] ?? null,
                'to' => $spec['type'] ?? null,
                'note' => 'destructive, allowed because this run forces it',
            ];
        }

        $item->action = $item->changes === [] ? PlanItem::ACTION_SKIP : PlanItem::ACTION_UPDATE;

        return $item;
    }

    /**
     * Keys the blueprint states that the component has no equivalent for.
     *
     * These are silently ignored on apply, so the only place they can be surfaced is
     * here — otherwise a blueprint carrying a stale Craft 4 key looks like it is doing
     * something and is doing nothing.
     *
     * @return string[]
     */
    private function ignoredKeys(array $stated, array $current): array
    {
        $ignored = [];

        foreach ($stated as $key => $value) {
            if (!array_key_exists($key, $current)) {
                $ignored[] = (string)$key;
                continue;
            }

            if ($key === 'settings' && is_array($value) && is_array($current['settings'])) {
                foreach (array_keys($value) as $settingKey) {
                    if (!array_key_exists($settingKey, $current['settings'])) {
                        $ignored[] = "settings.$settingKey";
                    }
                }
            }
        }

        return $ignored;
    }

    /**
     * Everything live that the blueprint does not mention.
     *
     * Pruning is off unless it is asked for twice — once in the plugin's settings and
     * once on the run — and sites are never included, because deleting a site deletes
     * content.
     *
     * @param string[]|null $only
     * @return PlanItem[]
     */
    private function planPrune(Blueprint $blueprint, ?array $only): array
    {
        if (!Plugin::getInstance()->getSettings()->allowPrune) {
            return [];
        }

        $items = [];
        $handlers = Plugin::getInstance()->handlers;

        foreach ($handlers->all() as $type => $handler) {
            if (in_array($type, ['sites', 'siteGroups'], true)) {
                continue;
            }
            if ($only !== null && !in_array($type, $only, true)) {
                continue;
            }
            if (!$blueprint->hasType($type)) {
                // A blueprint that says nothing about volumes is not asking for every
                // volume to be deleted.
                continue;
            }

            $declared = array_column($blueprint->specs($type), 'handle');

            foreach ($handler->all() as $component) {
                $handle = $handler->handleOf($component);
                if ($handle === null || in_array($handle, $declared, true)) {
                    continue;
                }

                $items[] = new PlanItem([
                    'type' => $type,
                    'typeLabel' => $handler::label(),
                    'stage' => 1000 - $handler::stage(),
                    'handle' => $handle,
                    'name' => $handler->nameOf($component),
                    'action' => PlanItem::ACTION_DELETE,
                    'existingUid' => $handler->uidOf($component),
                    'before' => $handler->dump($component),
                ]);
            }
        }

        return $items;
    }
}
