<?php

namespace justinholtweb\archie\components;

use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * Everything Archie needs to know about one kind of thing a blueprint can declare.
 *
 * A handler is the only place that knows how its component is read, written, described
 * and destroyed; the planner, applier, exporter and linter all work through this
 * interface and never touch a Craft service directly.
 */
interface ComponentHandlerInterface
{
    /** The canonical blueprint key, e.g. `entryTypes`. */
    public static function type(): string;

    /** Plural display name, e.g. “Entry types”. */
    public static function label(): string;

    /** Singular display name, e.g. “entry type”. */
    public static function singular(): string;

    /**
     * Apply order. Lower runs first. The numbers are spaced so a third-party handler can
     * slot between two of Archie's own without renumbering anything.
     */
    public static function stage(): int;

    /** A Craft CP icon name, used in the plan and export screens. */
    public static function icon(): string;

    /** @return object[] every live component of this kind */
    public function all(): array;

    public function find(string $handle): ?object;

    public function handleOf(object $component): ?string;

    public function nameOf(object $component): string;

    public function uidOf(object $component): ?string;

    /** Reads a live component into the blueprint's own vocabulary. */
    public function dump(object $component): array;

    /**
     * Fills in whatever a hand-written spec is allowed to leave out, and rewrites the
     * shapes that mean the same thing into one shape. Runs before planning, so the plan
     * and the apply are guaranteed to be looking at identical input.
     */
    public function normalize(array $spec): array;

    /**
     * Creates or updates the component.
     *
     * @return object|null the saved component, or null when nothing was saved
     * @throws \Throwable if the save fails
     */
    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object;

    public function delete(object $component): bool;

    /** Checks a spec for problems that would make the apply fail or misbehave. */
    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void;

    /**
     * Whether applying this spec over an existing component would destroy something.
     *
     * Returning a reason turns the plan item into a conflict, which blocks the apply
     * until the author either changes the blueprint or explicitly allows destructive
     * changes. Returning null means the update is safe.
     */
    public function conflict(array $spec, object $existing): ?string;

    /**
     * Whether this spec points at components that may not exist yet when it is applied,
     * and so needs a second save once everything else is in place.
     */
    public function needsRelink(array $spec): bool;

    /** The second save. Only called when {@see needsRelink()} returned true. */
    public function relink(array $spec, ApplyContext $context): void;
}
