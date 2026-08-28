<?php

namespace justinholtweb\archie\services;

use Craft;
use craft\base\Component;
use justinholtweb\archie\components\CategoryGroupHandler;
use justinholtweb\archie\components\ComponentHandlerInterface;
use justinholtweb\archie\components\EntryTypeHandler;
use justinholtweb\archie\components\FieldHandler;
use justinholtweb\archie\components\FilesystemHandler;
use justinholtweb\archie\components\GlobalSetHandler;
use justinholtweb\archie\components\RouteHandler;
use justinholtweb\archie\components\SectionHandler;
use justinholtweb\archie\components\SiteGroupHandler;
use justinholtweb\archie\components\SiteHandler;
use justinholtweb\archie\components\TagGroupHandler;
use justinholtweb\archie\components\TransformHandler;
use justinholtweb\archie\components\UserGroupHandler;
use justinholtweb\archie\components\VolumeHandler;
use justinholtweb\archie\events\RegisterComponentHandlersEvent;

/**
 * The registry of component handlers, in the order an apply has to run them.
 */
class Handlers extends Component
{
    /**
     * @event RegisterComponentHandlersEvent raised when the handler list is first built.
     */
    public const EVENT_REGISTER_COMPONENT_HANDLERS = 'registerComponentHandlers';

    /** @var ComponentHandlerInterface[]|null keyed by type, in stage order */
    private ?array $handlers = null;

    /** @return ComponentHandlerInterface[] keyed by type, in stage order */
    public function all(): array
    {
        if ($this->handlers !== null) {
            return $this->handlers;
        }

        $event = new RegisterComponentHandlersEvent([
            'handlers' => [
                SiteGroupHandler::class,
                SiteHandler::class,
                FilesystemHandler::class,
                TransformHandler::class,
                FieldHandler::class,
                EntryTypeHandler::class,
                SectionHandler::class,
                VolumeHandler::class,
                CategoryGroupHandler::class,
                TagGroupHandler::class,
                GlobalSetHandler::class,
                UserGroupHandler::class,
                RouteHandler::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_COMPONENT_HANDLERS, $event);

        $handlers = [];
        foreach ($event->handlers as $class) {
            if (!is_subclass_of($class, ComponentHandlerInterface::class)) {
                Craft::warning("Ignoring $class: it is not a component handler.", 'archie');
                continue;
            }
            $handlers[$class::type()] = Craft::createObject($class);
        }

        uasort($handlers, fn($a, $b) => $a::stage() <=> $b::stage());

        return $this->handlers = $handlers;
    }

    public function get(string $type): ?ComponentHandlerInterface
    {
        return $this->all()[$type] ?? null;
    }

    /** @return string[] every canonical component type, in stage order */
    public function types(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, string> type => plural label, in stage order */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->all() as $type => $handler) {
            $labels[$type] = $handler::label();
        }
        return $labels;
    }
}
