<?php

namespace justinholtweb\archie\models;

use craft\base\Model;

/**
 * What the linter found. Errors stop an apply; warnings are things worth knowing that
 * Archie is nevertheless willing to do.
 */
class LintReport extends Model
{
    /** @var array<int, array{message: string, where: ?string}> */
    public array $errors = [];

    /** @var array<int, array{message: string, where: ?string}> */
    public array $warnings = [];

    public function error(string $message, ?string $where = null): void
    {
        $this->errors[] = ['message' => $message, 'where' => $where];
    }

    public function warn(string $message, ?string $where = null): void
    {
        $this->warnings[] = ['message' => $message, 'where' => $where];
    }

    public function isClean(): bool
    {
        return $this->errors === [];
    }

    /** @return string[] */
    public function errorMessages(): array
    {
        return array_map(
            fn(array $e) => $e['where'] !== null ? "{$e['where']}: {$e['message']}" : $e['message'],
            $this->errors
        );
    }

    /** @return string[] */
    public function warningMessages(): array
    {
        return array_map(
            fn(array $w) => $w['where'] !== null ? "{$w['where']}: {$w['message']}" : $w['message'],
            $this->warnings
        );
    }
}
