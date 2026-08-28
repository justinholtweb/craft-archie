# Extending Archie

Archie is built out of **component handlers**. There is one per kind of thing a blueprint can
declare, and it owns everything about that kind: how it is found, read, written, described, linted
and deleted. The linter, planner, applier, exporter and the whole CP work through the handler
interface and never touch a Craft service directly, so adding a component type is a matter of adding
one class.

## Registering a handler

```php
use justinholtweb\archie\events\RegisterComponentHandlersEvent;
use justinholtweb\archie\services\Handlers;
use yii\base\Event;

Event::on(
    Handlers::class,
    Handlers::EVENT_REGISTER_COMPONENT_HANDLERS,
    function(RegisterComponentHandlersEvent $event) {
        $event->handlers[] = \mine\archie\ProductTypeHandler::class;
    }
);
```

Register it in your plugin's `init()`. The event fires once, the first time the registry is built.

## Writing one

Extend `BaseComponentHandler`, which supplies handle derivation, the usual lint checks, enum
coercion and the "save it and report Craft's own validation errors" plumbing.

```php
namespace mine\archie;

use Craft;
use justinholtweb\archie\components\BaseComponentHandler;
use justinholtweb\archie\models\ApplyContext;

class ProductTypeHandler extends BaseComponentHandler
{
    private const ATTRIBUTES = ['name', 'handle', 'skuFormat', 'maxVariants'];

    public static function type(): string      { return 'productTypes'; }
    public static function label(): string     { return 'Product types'; }
    public static function singular(): string  { return 'product type'; }
    public static function icon(): string      { return 'tag'; }

    /**
     * Apply order. Archie's own handlers sit at 10–130, spaced so a third-party one can
     * slot between two of them without renumbering anything.
     */
    public static function stage(): int        { return 75; }

    public function all(): array
    {
        return Plugin::getInstance()->getProductTypes()->getAllProductTypes();
    }

    public function find(string $handle): ?object
    {
        return Plugin::getInstance()->getProductTypes()->getProductTypeByHandle($handle);
    }

    /** Reads a live component into the blueprint's vocabulary. */
    public function dump(object $component): array
    {
        return $this->read($component, self::ATTRIBUTES);
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $productType = $existing ?? new ProductType();
        $this->assign($productType, $spec, self::ATTRIBUTES);

        if (!Plugin::getInstance()->getProductTypes()->saveProductType($productType)) {
            throw $this->failed($productType, "the product type “{$spec['handle']}”");
        }

        return $productType;
    }

    public function delete(object $component): bool
    {
        return Plugin::getInstance()->getProductTypes()->deleteProductTypeById($component->id);
    }
}
```

That is the whole minimum. Everything else — planning, diffing, the CP screens, export, rollback —
follows from it.

## The contract that matters

**`dump()` and the blueprint must use the same words.** A plan compares the spec you wrote against
`dump()` of the live component, key by key. If `dump()` emits `fsHandle` and blueprints say `fs`,
every plan reports a change that applying cannot fix. Pick one vocabulary and use it in both
directions; `normalize()` is where you accept the other spellings.

**Only keys `dump()` returns are ever compared.** A key absent from the dump is treated as one this
handler does not manage and surfaced in the plan as *ignored*. Use `read()` so every managed
attribute is always present, even when null.

**`normalize()` runs once, before planning.** It fills in what a hand-written spec may leave out and
rewrites equivalent shapes into one shape, so the plan and the apply are guaranteed to see identical
input. Record anything you invent rather than read:

```php
$spec[self::DERIVED_KEY][] = 'name';
```

Derived values are used when creating and ignored when comparing, which is what stops a name
invented from a handle overwriting a real one.

## The optional hooks

**`lint()`** — check whatever would make the apply fail or misbehave. Errors block; warnings do not.
Call `parent::lint()` first for the handle and field-layout checks.

**`conflict()`** — return a reason when applying over an existing component would destroy something.
The plan item becomes a blocked conflict until the run is forced. Archie uses this for a field
changing type and a structure section being converted.

**`needsRelink()` / `relink()`** — for components that point at things that may not exist yet when
they are saved. Save what you can in `apply()`, call `$context->defer()`, and the applier calls
`relink()` once everything else is in place. This is how a Matrix field created in the same run as
its entry types works.

**`ApplyContext`** — `warn()` for something the author should know, `note()` for the record,
`defer()` to ask for a second save.

## Value serialisers and layouts

Two helpers are worth knowing about rather than reimplementing:

- `justinholtweb\archie\helpers\FieldLayoutHelper` converts between Craft's field layouts and the
  flat, handle-based canonical shape everything else uses. `build()` reports the elements it could
  not resolve, which Craft's own builder does not.
- `justinholtweb\archie\helpers\Refs` translates between handles and UIDs inside field settings, in
  both directions. If your component's settings reference other components, run them through it.

## Testing a handler

The unit suite runs without Craft. Anything a handler does that can be tested that way — shape
normalisation, handle derivation, reference translation — belongs there. Behaviour that needs a live
Craft is best checked by applying a blueprint twice: the second plan should report no changes at
all. That round trip catches more handler bugs than any single assertion, because it fails whenever
`dump()` and `apply()` disagree about anything.
