<?php

namespace justinholtweb\archie\models;

use craft\base\Model;

/**
 * What actually happened when a plan was applied.
 */
class ApplyResult extends Model
{
    /** The recorded run, or null if nothing was written. */
    public ?int $runId = null;

    /** @var array<int, array{type: string, handle: string, action: string}> */
    public array $applied = [];

    /** @var string[] */
    public array $warnings = [];

    /** Set when the apply stopped early. Everything before it still happened. */
    public ?string $error = null;

    /** The item the apply died on, for pointing at the right line of the blueprint. */
    public ?string $failedOn = null;

    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    public function count(): int
    {
        return count($this->applied);
    }
}
