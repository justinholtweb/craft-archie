<?php

namespace justinholtweb\archie\models;

use craft\base\Model;

/**
 * The result of comparing a blueprint against the live content model: an ordered list
 * of everything that would happen, with nothing having happened yet.
 */
class Plan extends Model
{
    public ?Blueprint $blueprint = null;

    /** @var PlanItem[] in apply order */
    public array $items = [];

    /** Lint failures. A plan with errors cannot be applied. */
    public array $errors = [];

    /** Lint advisories. Worth reading, but they do not block. */
    public array $warnings = [];

    /** Whether the environment itself would refuse the write (read-only project config). */
    public ?string $blocked = null;

    /**
     * @return array<string, int> counts keyed by action, always including every action
     */
    public function counts(): array
    {
        $counts = [
            PlanItem::ACTION_CREATE => 0,
            PlanItem::ACTION_UPDATE => 0,
            PlanItem::ACTION_SKIP => 0,
            PlanItem::ACTION_CONFLICT => 0,
            PlanItem::ACTION_DELETE => 0,
        ];
        foreach ($this->items as $item) {
            $counts[$item->action]++;
        }
        return $counts;
    }

    /** Items grouped by component type, preserving apply order within each group. */
    public function byType(): array
    {
        $grouped = [];
        foreach ($this->items as $item) {
            $grouped[$item->type][] = $item;
        }
        return $grouped;
    }

    /** @return PlanItem[] */
    public function conflicts(): array
    {
        return array_values(array_filter($this->items, fn(PlanItem $i) => $i->isBlocking()));
    }

    /** @return PlanItem[] everything the apply would actually act on */
    public function actionable(): array
    {
        return array_values(array_filter(
            $this->items,
            fn(PlanItem $i) => in_array($i->action, [PlanItem::ACTION_CREATE, PlanItem::ACTION_UPDATE, PlanItem::ACTION_DELETE], true)
        ));
    }

    /** Whether applying is allowed: no lint errors, no conflicts, no environment block. */
    public function isApplicable(): bool
    {
        return $this->errors === [] && $this->conflicts() === [] && $this->blocked === null;
    }

    /** Whether applying would do anything at all. */
    public function hasWork(): bool
    {
        return $this->actionable() !== [];
    }
}
