<?php

namespace justinholtweb\archie\controllers;

use craft\web\Controller;

/**
 * Shared access rules for Archie's CP screens.
 *
 * Everything here edits — or describes editing — the content model, which in Craft is
 * admin territory. Reading is allowed wherever an admin is allowed; writing additionally
 * requires the environment to permit admin changes, which is why you can plan a blueprint
 * on production and only apply it where Craft would let you.
 */
abstract class BaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(in_array($action->id, $this->writeActions(), true));

        return true;
    }

    /**
     * Actions that write, and so need `allowAdminChanges`.
     *
     * @return string[]
     */
    protected function writeActions(): array
    {
        return [];
    }
}
