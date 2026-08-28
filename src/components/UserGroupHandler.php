<?php

namespace justinholtweb\archie\components;

use Craft;
use craft\models\UserGroup;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * User groups, including their permissions.
 *
 * Permissions are the one place a blueprint can be quietly wrong in a way that matters,
 * so an unknown permission name is an error rather than something to drop on the floor.
 */
class UserGroupHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['name', 'handle', 'description'];

    public static function type(): string
    {
        return 'userGroups';
    }

    public static function label(): string
    {
        return 'User groups';
    }

    public static function singular(): string
    {
        return 'user group';
    }

    public static function stage(): int
    {
        return 120;
    }

    public static function icon(): string
    {
        return 'users';
    }

    public function all(): array
    {
        return Craft::$app->getUserGroups()->getAllGroups();
    }

    public function find(string $handle): ?object
    {
        return Craft::$app->getUserGroups()->getGroupByHandle($handle);
    }

    public function dump(object $component): array
    {
        /** @var UserGroup $component */
        $dump = $this->read($component, self::ATTRIBUTES);

        $permissions = $component->id !== null
            ? Craft::$app->getUserPermissions()->getPermissionsByGroupId($component->id)
            : [];
        sort($permissions);
        $dump['permissions'] = $permissions;

        return $dump;
    }

    public function normalize(array $spec): array
    {
        $spec = parent::normalize($spec);

        if (isset($spec['permissions']) && is_array($spec['permissions'])) {
            // Craft stores permissions lowercased, and compares them that way too.
            $permissions = array_map('strtolower', array_map('strval', $spec['permissions']));
            sort($permissions);
            $spec['permissions'] = $permissions;
        }

        return $spec;
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        parent::lint($spec, $report, $blueprint);

        if (!isset($spec['permissions'])) {
            return;
        }

        $where = 'user group “' . ($spec['handle'] ?: '?') . '”';
        $known = $this->knownPermissions();

        foreach ($spec['permissions'] as $permission) {
            if (in_array($permission, $known, true)) {
                continue;
            }
            $report->error(
                sprintf('“%s” is not a permission on this install. Permission names include the target UID, e.g. `viewentries:<section uid>`.', $permission),
                $where
            );
        }
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $group = $existing instanceof UserGroup ? $existing : new UserGroup();

        $this->assign($group, $spec, self::ATTRIBUTES);

        if (!Craft::$app->getUserGroups()->saveGroup($group)) {
            throw $this->failed($group, "the user group “{$spec['handle']}”");
        }

        if (isset($spec['permissions']) && $group->id !== null) {
            Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, $spec['permissions']);
        }

        return $group;
    }

    /**
     * Every permission name Craft currently knows about, flattened out of the nested
     * structure the permissions service returns.
     *
     * @return string[]
     */
    private function knownPermissions(): array
    {
        $names = [];

        $walk = function(array $permissions) use (&$walk, &$names): void {
            foreach ($permissions as $permission) {
                if (isset($permission['key'])) {
                    $names[] = strtolower($permission['key']);
                }
                if (!empty($permission['nested'])) {
                    $walk($permission['nested']);
                }
            }
        };

        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            $walk($group['permissions'] ?? []);
        }

        return $names;
    }

    public function delete(object $component): bool
    {
        /** @var UserGroup $component */
        return Craft::$app->getUserGroups()->deleteGroup($component);
    }
}
