<?php

namespace justinholtweb\archie\events;

use yii\base\Event;

/**
 * Raised so a plugin can teach Archie about a component type of its own.
 */
class RegisterComponentHandlersEvent extends Event
{
    /** @var string[] handler class names, each implementing ComponentHandlerInterface */
    public array $handlers = [];
}
