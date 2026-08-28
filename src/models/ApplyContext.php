<?php

namespace justinholtweb\archie\models;

use craft\base\Model;

/**
 * Carried through an apply so handlers can report on what happened without any of them
 * needing to know about the run as a whole.
 */
class ApplyContext extends Model
{
    /** @var string[] */
    public array $warnings = [];

    /**
     * Components saved with some of their references stripped because the things they
     * point at did not exist yet. The applier saves each of these a second time once
     * everything else is in place.
     *
     * @var array<int, array{type: string, handle: string, spec: array}>
     */
    public array $deferrals = [];

    public function defer(string $type, string $handle, array $spec): void
    {
        $this->deferrals[] = ['type' => $type, 'handle' => $handle, 'spec' => $spec];
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
