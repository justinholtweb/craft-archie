<?php

namespace justinholtweb\archie\models;

use craft\base\Model;
use justinholtweb\archie\helpers\Differ;

/**
 * One line of a plan: what Archie intends to do with a single component.
 */
class PlanItem extends Model
{
    /** The component does not exist yet and will be created. */
    public const ACTION_CREATE = 'create';
    /** The component exists and one or more managed attributes differ. */
    public const ACTION_UPDATE = 'update';
    /** The component exists and already matches the blueprint. Nothing will happen. */
    public const ACTION_SKIP = 'skip';
    /** Something exists under this handle that Archie will not overwrite. Blocks the apply. */
    public const ACTION_CONFLICT = 'conflict';
    /** The component exists, is not in the blueprint, and pruning was requested. */
    public const ACTION_DELETE = 'delete';

    public string $type = '';
    public string $typeLabel = '';
    public string $handle = '';
    public string $name = '';
    public string $action = self::ACTION_SKIP;
    public int $stage = 0;

    /** @var array<int, array{path: string, from: mixed, to: mixed, note?: string}> */
    public array $changes = [];

    /** Why this item is a conflict, or why an otherwise-changed item is being skipped. */
    public ?string $reason = null;

    /**
     * Keys the blueprint sets that this component has no equivalent for, and which an
     * apply will therefore ignore.
     *
     * @var string[]
     */
    public array $ignored = [];

    /** The blueprint spec this item came from. Empty for deletes. */
    public array $spec = [];

    /** UID of the live component, when one was matched. */
    public ?string $existingUid = null;

    /** A dump of the live component before the change, kept so the apply can snapshot it. */
    public ?array $before = null;

    public function isNoop(): bool
    {
        return $this->action === self::ACTION_SKIP;
    }

    public function isBlocking(): bool
    {
        return $this->action === self::ACTION_CONFLICT;
    }

    public function isDestructive(): bool
    {
        return $this->action === self::ACTION_DELETE;
    }

    /**
     * The changes with their values rendered for display, so a template never has to
     * decide how to print a boolean or a nested array.
     *
     * @return array<int, array{path: string, from: string, to: string, note: ?string}>
     */
    public function renderedChanges(): array
    {
        return array_map(fn(array $change) => [
            'path' => $change['path'],
            'from' => Differ::render($change['from']),
            'to' => Differ::render($change['to']),
            'note' => $change['note'] ?? null,
        ], $this->changes);
    }

    /** A short human summary of what changes, for table rows and console output. */
    public function summary(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE => 'Will be created',
            self::ACTION_UPDATE => count($this->changes) === 1
                ? '1 change: ' . $this->changes[0]['path']
                : count($this->changes) . ' changes: ' . implode(', ', array_slice(array_column($this->changes, 'path'), 0, 4))
                    . (count($this->changes) > 4 ? ', …' : ''),
            self::ACTION_SKIP => $this->reason ?? 'Already matches',
            self::ACTION_CONFLICT => $this->reason ?? 'Conflict',
            self::ACTION_DELETE => 'Will be deleted',
            default => '',
        };
    }
}
